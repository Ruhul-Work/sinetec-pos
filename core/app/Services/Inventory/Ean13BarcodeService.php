<?php

namespace App\Services\Inventory;

use App\Models\backend\FinishedProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Ean13BarcodeService
{
    private const INTERNAL_NUMBER_START = 200_000_000_000;

    /**
     * Return the existing EAN-13 or permanently assign an internal one.
     */
    public function assignTo(FinishedProduct $product): string
    {
        return DB::transaction(function () use ($product): string {
            $lockedProduct = FinishedProduct::withTrashed()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            if (filled($lockedProduct->barcode)) {
                $barcode = (string) $lockedProduct->barcode;

                if (! $this->isValid($barcode)) {
                    $message = $lockedProduct->name.' has an invalid EAN-13 barcode. '
                        .'Clear or correct its barcode in Finished Products, then try again.';

                    throw ValidationException::withMessages([
                        'products' => $message,
                    ]);
                }

                return $barcode;
            }

            $barcode = $this->nextAvailableBarcode((int) $lockedProduct->getKey());
            $lockedProduct->forceFill(['barcode' => $barcode])->save();

            return $barcode;
        }, 3);
    }

    public function isValid(string $barcode): bool
    {
        if (! preg_match('/^\d{13}$/', $barcode)) {
            return false;
        }

        return (int) $barcode[12] === $this->calculateCheckDigit(substr($barcode, 0, 12));
    }

    public function calculateCheckDigit(string $payload): int
    {
        if (! preg_match('/^\d{12}$/', $payload)) {
            throw new \InvalidArgumentException('An EAN-13 payload must contain exactly 12 digits.');
        }

        $sum = 0;

        for ($index = 0; $index < 12; $index++) {
            $digit = (int) $payload[$index];
            $sum += $index % 2 === 0 ? $digit : $digit * 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    public function renderSvg(string $barcode): string
    {
        if (! $this->isValid($barcode)) {
            throw new \InvalidArgumentException('A valid EAN-13 barcode is required for rendering.');
        }

        $modules = $this->encodeModules($barcode);
        $bars = [];
        $moduleWidth = 2;
        $quietZone = 12;
        $normalHeight = 48;
        $guardHeight = 54;

        foreach (str_split($modules) as $position => $module) {
            if ($module !== '1') {
                continue;
            }

            $isGuard = $position < 3
                || ($position >= 45 && $position <= 49)
                || $position >= 92;

            $x = $quietZone + ($position * $moduleWidth);
            $height = $isGuard ? $guardHeight : $normalHeight;
            $bars[] = sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $moduleWidth, $height);
        }

        $width = ($quietZone * 2) + (strlen($modules) * $moduleWidth);

        $template = '<svg class="ean13-barcode" xmlns="http://www.w3.org/2000/svg" '
            .'viewBox="0 0 %d %d" role="img" aria-label="EAN-13 barcode %s">'
            .'<g fill="#000">%s</g></svg>';

        return sprintf(
            $template,
            $width,
            $guardHeight,
            e($barcode),
            implode('', $bars),
        );
    }

    private function nextAvailableBarcode(int $productId): string
    {
        $number = self::INTERNAL_NUMBER_START + $productId;

        while ($number <= 999_999_999_999) {
            $payload = str_pad((string) $number, 12, '0', STR_PAD_LEFT);
            $candidate = $payload.$this->calculateCheckDigit($payload);

            $alreadyUsed = FinishedProduct::withTrashed()
                ->where('barcode', $candidate)
                ->exists();

            if (! $alreadyUsed) {
                return $candidate;
            }

            $number++;
        }

        throw ValidationException::withMessages([
            'products' => 'No internal EAN-13 number is currently available.',
        ]);
    }

    private function encodeModules(string $barcode): string
    {
        $leftOdd = [
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101', '4' => '0100011',
            '5' => '0110001', '6' => '0101111', '7' => '0111011', '8' => '0110111', '9' => '0001011',
        ];
        $leftEven = [
            '0' => '0100111', '1' => '0110011', '2' => '0011011', '3' => '0100001', '4' => '0011101',
            '5' => '0111001', '6' => '0000101', '7' => '0010001', '8' => '0001001', '9' => '0010111',
        ];
        $right = [
            '0' => '1110010', '1' => '1100110', '2' => '1101100', '3' => '1000010', '4' => '1011100',
            '5' => '1001110', '6' => '1010000', '7' => '1000100', '8' => '1001000', '9' => '1110100',
        ];
        $parity = [
            '0' => 'LLLLLL', '1' => 'LLGLGG', '2' => 'LLGGLG', '3' => 'LLGGGL', '4' => 'LGLLGG',
            '5' => 'LGGLLG', '6' => 'LGGGLL', '7' => 'LGLGLG', '8' => 'LGLGGL', '9' => 'LGGLGL',
        ];

        $modules = '101';
        $leftParity = $parity[$barcode[0]];

        for ($index = 1; $index <= 6; $index++) {
            $digit = $barcode[$index];
            $modules .= $leftParity[$index - 1] === 'L' ? $leftOdd[$digit] : $leftEven[$digit];
        }

        $modules .= '01010';

        for ($index = 7; $index <= 12; $index++) {
            $modules .= $right[$barcode[$index]];
        }

        return $modules.'101';
    }
}
