<style>
    .shopee-fields-card {
        position: relative;
        border: 1px solid rgba(238, 77, 45, .22);
        background: linear-gradient(120deg, rgba(238, 77, 45, .06), rgba(255, 115, 55, .03) 55%, rgba(255, 193, 7, .05));
        border-radius: 16px;
        padding: 22px 24px 8px;
        margin-bottom: 15px;
        overflow: hidden;
    }

    .shopee-fields-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, #ee4d2d, #ff7337 55%, #ffc107);
    }

    .shopee-badge {
        width: 46px;
        height: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        flex: 0 0 auto;
        color: #fff;
        font-size: 24px;
        background: linear-gradient(135deg, #ee4d2d, #ff7337);
        box-shadow: 0 8px 18px rgba(238, 77, 45, .35);
    }

    .shopee-status-badge {
        background: rgba(238, 77, 45, .12);
        color: #ee4d2d;
        font-weight: 600;
        border-radius: 30px;
        padding: 6px 14px;
    }

    .shopee-switch {
        position: relative;
        display: inline-block;
        width: 84px;
        height: 40px;
        cursor: pointer;
        user-select: none;
    }

    .shopee-switch-input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }

    .shopee-switch-slider {
        position: absolute;
        inset: 0;
        border-radius: 100px;
        background: #e6e9ee;
        box-shadow: inset 0 2px 8px rgba(20, 26, 31, .12), 0 1px 0 rgba(255, 255, 255, .8);
        transition: background .3s ease, box-shadow .3s ease;
    }

    .shopee-switch-slider::before {
        content: '';
        position: absolute;
        top: 4px;
        left: 4px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(180deg, #fff, #f3f5f7);
        box-shadow: 0 3px 8px rgba(20, 26, 31, .25), 0 1px 2px rgba(20, 26, 31, .15);
        transition: transform .35s cubic-bezier(.4, 0, .2, 1);
    }

    .shopee-switch-slider::after {
        content: 'OFF';
        position: absolute;
        top: 50%;
        right: 12px;
        transform: translateY(-50%);
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 1.5px;
        color: #98a2ae;
        transition: all .3s ease;
    }

    .shopee-switch-input:checked+.shopee-switch-slider {
        background: linear-gradient(135deg, #ee4d2d, #ff6a3c);
        box-shadow: 0 0 0 5px rgba(238, 77, 45, .15), inset 0 2px 8px rgba(120, 26, 0, .18);
    }

    .shopee-switch-input:checked+.shopee-switch-slider::before {
        transform: translateX(44px);
        background: linear-gradient(180deg, #ffffff, #ffe8e2);
    }

    .shopee-switch-input:checked+.shopee-switch-slider::after {
        content: 'ON';
        right: auto;
        left: 14px;
        color: #ffffff;
        text-shadow: 0 1px 2px rgba(120, 26, 0, .25);
    }

    .shopee-switch-input:focus-visible+.shopee-switch-slider {
        box-shadow: 0 0 0 5px rgba(238, 77, 45, .2), 0 0 0 2px #fff, 0 0 0 4px #ee4d2d;
    }

    .shopee-vis-hint {
        font-size: 13px;
        font-weight: 600;
        transition: color .3s ease;
    }

    .shopee-vis-hint.shopee-vis-on {
        color: #ee4d2d;
    }

    .shopee-vis-hint.shopee-vis-off {
        color: #98a2ae;
    }
</style>

<div class="col-lg-12">
    <div class="shopee-fields-card mt-4">
        <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
            <span class="shopee-badge">
                <iconify-icon icon="lucide:store"></iconify-icon>
            </span>
            <div>
                <h6 class="fw-bold text-gray-900 mb-0">Shopee Marketplace</h6>
                <p class="text-sm text-muted mb-0">Configure Shopee pricing details and control listing visibility.</p>
            </div>
            <span class="badge shopee-status-badge ms-auto">Optional</span>
        </div>

        <div class="row">
            <div class="col-lg-3 col-sm-6 col-12">
                <div class="mb-3">
                    <label class="form-label" for="shopee_mrp">Shopee MRP</label>
                    <input type="number" class="form-control" name="shopee_mrp" id="shopee_mrp" min="0" step="0.01"
                        placeholder="e.g. 1200" value="{{ old('shopee_mrp', $product->shopee_mrp ?? null) }}">
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="mb-3">
                    <label class="form-label" for="shopee_price">Shopee Price</label>
                    <input type="number" class="form-control" name="shopee_price" id="shopee_price" min="0" step="0.01"
                        placeholder="e.g. 990" value="{{ old('shopee_price', $product->shopee_price ?? null) }}">
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="mb-3">
                    <label class="form-label" for="shopee_url">Shopee URL</label>
                    <input type="text" class="form-control" name="shopee_url" id="shopee_url"
                        placeholder="https://shopee.com.mm/product-name-i.xxx"
                        value="{{ old('shopee_url', $product->shopee_url ?? null) }}">
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="mb-3">
                    <label class="form-label d-block">Show on Shopee</label>
                    <input type="hidden" name="shopee_visibility" value="0">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <label class="shopee-switch" for="shopeeVisibility">
                            <input class="shopee-switch-input" type="checkbox" role="switch" id="shopeeVisibility"
                                name="shopee_visibility" value="1"
                                {{ old('shopee_visibility', $product->shopee_visibility ?? false) ? 'checked' : '' }}>
                            <span class="shopee-switch-slider"></span>
                        </label>
                        <span class="shopee-vis-hint" id="shopeeVisibilityText"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function syncShopeeVisibilityText() {
        var on = $('#shopeeVisibility').is(':checked');
        $('#shopeeVisibilityText').text(on ? 'Visible on Shopee' : 'Hidden from Shopee')
            .toggleClass('shopee-vis-on', on)
            .toggleClass('shopee-vis-off', !on);
    }
    $(document).ready(function() {
        $('#shopeeVisibility').on('change', syncShopeeVisibilityText);
        syncShopeeVisibilityText();
    });
</script>
