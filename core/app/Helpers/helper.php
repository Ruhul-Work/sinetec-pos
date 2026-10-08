<?php

use App\Models\backend\FiscalYear;
use App\Support\BranchScope;
use App\Support\WarehouseScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

if (! function_exists('image')) {
    /**
     * Resolve a stored, application-relative image path to a public URL.
     */
    function image(?string $source, bool $cdn = false): string
    {
        $fallback = 'theme/admin/assets/images/avatar/avatar1.png';

        if ($source === null || trim($source) === '') {
            return asset($fallback);
        }

        $source = trim($source);

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return $source;
        }

        $relativePath = ltrim(str_replace('\\', '/', $source), '/');
        $rootPublicPath = base_path('../'.$relativePath);
        $laravelPublicPath = public_path($relativePath);

        if (! is_file($rootPublicPath) && ! is_file($laravelPublicPath)) {
            return asset($fallback);
        }

        return asset($relativePath);
    }
}

if (! function_exists('uploadImage')) {
    /**
     * Store a validated image under the web-root uploads directory.
     *
     * The optional watermark/quality arguments remain for compatibility with
     * retained callers; image transformation will be introduced separately.
     */
    function uploadImage(
        mixed $file,
        string $directory,
        ?string $watermarkPath = null,
        int $quality = 80
    ): ?string {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }

        $directory = trim(str_replace(['..', '\\'], ['', '/'], $directory), '/');

        if ($directory === '') {
            throw new InvalidArgumentException('An upload directory is required.');
        }

        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (! in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException('Unsupported image format.');
        }

        $relativeDirectory = 'uploads/'.$directory.'/'.now()->format('Y/m/d');
        $absoluteDirectory = base_path('../'.$relativeDirectory);
        File::ensureDirectoryExists($absoluteDirectory, 0755, true);

        $filename = Str::uuid()->toString().'.'.$extension;
        $file->move($absoluteDirectory, $filename);

        return $relativeDirectory.'/'.$filename;
    }
}

if (! function_exists('current_branch_id')) {
    function current_branch_id(): ?int
    {
        return BranchScope::currentId();
    }
}

if (! function_exists('current_warehouse_id')) {
    function current_warehouse_id(): ?int
    {
        return WarehouseScope::get();
    }
}

if (! function_exists('currentFiscalYear')) {
    function currentFiscalYear(): ?FiscalYear
    {
        return FiscalYear::query()->where('is_active', true)->first();
    }
}

if (! function_exists('requireFiscalYear')) {
    function requireFiscalYear(): FiscalYear
    {
        return currentFiscalYear()
            ?? abort(503, 'Fiscal year is not configured.');
    }
}
