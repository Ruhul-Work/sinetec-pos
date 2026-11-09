<div class="modal-header py-16 px-24 border-0">
    <h5 class="modal-title">Edit Account</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
    <form method="POST" action="{{ route('accounts.update', $account) }}" data-ajax="true">
        @csrf
        @include('backend.modules.account.modal.fields', [
            'account' => $account,
            'isEdit' => true,
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
                Update Account
            </button>
        </div>
    </form>
</div>

<script>
    S2.auto($('.modal.show'));
</script>
