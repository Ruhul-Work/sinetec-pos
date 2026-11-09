<div class="modal-header py-16 px-24 border-0">
    <h5 class="modal-title">Add Account Type</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
    <form method="POST" action="{{ route('account-types.store') }}" data-ajax="true">
        @csrf
        @include('backend.modules.accountType.modal.fields', [
            'type' => null,
            'isEdit' => false,
        ])

        <div class="d-flex justify-content-end gap-2 mt-4">
            <button
                type="button"
                class="btn btn-outline-secondary app-action-button"
                data-bs-dismiss="modal"
            >
                Cancel
            </button>
            <button type="submit" class="btn btn-primary app-action-button app-primary-action">
                Save Type
            </button>
        </div>
    </form>
</div>

<script>
    S2.auto($('.modal.show'));
</script>
