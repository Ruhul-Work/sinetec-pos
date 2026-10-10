(function (window, document) {
  'use strict';

  const INPUT_SELECTOR = 'input[type="file"].js-image-picker';
  let pickerCounter = 0;

  function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  function fileKey(file) {
    return [file.name, file.size, file.lastModified].join(':');
  }

  function fileNameFromUrl(url) {
    try {
      return decodeURIComponent(String(url).split('?')[0].split('/').pop()) || 'Current image';
    } catch (_) {
      return 'Current image';
    }
  }

  class SinetecImagePicker {
    constructor(input) {
      this.input = input;
      this.multiple = input.multiple;
      this.required = input.required;
      this.removable = input.dataset.removable !== 'false';
      this.maxFiles = this.multiple
        ? Math.max(parseInt(input.dataset.maxFiles || '10', 10), 1)
        : 1;
      this.maxSize = Math.max(parseInt(input.dataset.maxSizeKb || '2048', 10), 1) * 1024;
      this.removeName = input.dataset.removeName || (this.multiple ? 'remove_images' : 'remove_image');
      this.files = [];
      this.objectUrls = [];
      this.existingImages = this.readExistingImages();

      this.build();
      this.renderExistingImages();
      this.bind();
      this.refresh();
    }

    readExistingImages() {
      if (this.multiple && this.input.dataset.existing) {
        try {
          const values = JSON.parse(this.input.dataset.existing);
          if (Array.isArray(values)) {
            return values.map((value, index) => this.normalizeExisting(value, index)).filter(Boolean);
          }
        } catch (_) {
          // Invalid optional JSON should not break the underlying file input.
        }
      }

      const url = this.input.dataset.existingUrl || '';
      if (!url) return [];

      return [{
        key: this.input.dataset.existingKey || url,
        url,
        name: this.input.dataset.existingName || fileNameFromUrl(url)
      }];
    }

    normalizeExisting(value, index) {
      if (typeof value === 'string') {
        return {key: value, url: value, name: fileNameFromUrl(value)};
      }

      if (!value || typeof value !== 'object') return null;
      const url = value.url || value.path || '';
      if (!url) return null;

      return {
        key: String(value.id ?? value.key ?? value.path ?? index),
        url,
        name: value.name || fileNameFromUrl(url)
      };
    }

    build() {
      const inputName = this.input.name.replace(/\[\]$/, '');
      const errorClass = `${inputName.replace(/[.\[\]]/g, '_')}-error`;
      const possibleError = this.input.nextElementSibling;
      const existingError = possibleError?.classList.contains('invalid-feedback') ? possibleError : null;

      this.root = document.createElement('div');
      this.root.className = `sinetec-image-picker${this.multiple ? ' is-multiple' : ''}`;
      this.input.parentNode.insertBefore(this.root, this.input);
      this.root.appendChild(this.input);

      if (!this.input.id) {
        pickerCounter += 1;
        this.input.id = `sinetec-image-picker-${pickerCounter}`;
      }

      this.input.classList.add('sinetec-image-picker__input');
      this.input.dataset.imagePickerInitialized = 'true';

      if (!this.multiple) {
        this.removeInput = document.createElement('input');
        this.removeInput.type = 'hidden';
        this.removeInput.name = this.removeName;
        this.removeInput.value = '0';
        this.removeInput.dataset.imagePickerRemoveInput = 'true';
        this.root.appendChild(this.removeInput);
      }

      this.grid = document.createElement('div');
      this.grid.className = 'sinetec-image-picker__grid';
      this.grid.dataset.imagePickerGrid = 'true';
      this.root.appendChild(this.grid);

      this.dropzone = document.createElement('button');
      this.dropzone.type = 'button';
      this.dropzone.className = 'sinetec-image-picker__dropzone';
      this.dropzone.dataset.imagePickerDropzone = 'true';
      this.dropzone.innerHTML = `
        <span class="sinetec-image-picker__icon">
          <iconify-icon icon="solar:gallery-add-outline"></iconify-icon>
        </span>
        <strong>${this.multiple ? 'Add images' : (this.existingImages.length ? 'Replace image' : 'Choose image')}</strong>
        <small>Click or drop here</small>`;
      this.grid.appendChild(this.dropzone);

      this.help = document.createElement('small');
      this.help.className = 'sinetec-image-picker__help';
      this.help.textContent = this.input.dataset.help || this.defaultHelpText();
      this.root.appendChild(this.help);

      this.error = existingError || document.createElement('div');
      if (!existingError) {
        this.error.className = `invalid-feedback d-block ${errorClass}`;
        this.error.style.display = 'none';
      }
      this.error.dataset.imagePickerError = 'true';
      this.root.appendChild(this.error);
    }

    defaultHelpText() {
      const formatLabels = {
        'image/jpeg': 'JPG/JPEG',
        'image/png': 'PNG'
      };
      const formats = this.input.accept
        ? this.input.accept.split(',').map((value) => {
            const rule = value.trim().toLowerCase();
            return formatLabels[rule] || rule.split('/').pop().replace(/^\./, '').toUpperCase();
          }).join(', ')
        : 'Image';
      const size = formatBytes(this.maxSize);

      return this.multiple
        ? `${formats}; up to ${this.maxFiles} images, maximum ${size} each.`
        : `${formats}; maximum ${size}.`;
    }

    bind() {
      this.dropzone.addEventListener('click', () => this.input.click());

      this.root.addEventListener('click', (event) => {
        const replaceButton = event.target.closest('[data-image-picker-replace]');
        if (replaceButton) {
          event.preventDefault();
          this.input.click();
          return;
        }

        const removeExisting = event.target.closest('[data-image-picker-remove-existing]');
        if (removeExisting) {
          event.preventDefault();
          this.removeExisting(removeExisting.closest('[data-image-picker-existing]'));
          return;
        }

        const removeNew = event.target.closest('[data-image-picker-remove-new]');
        if (removeNew) {
          event.preventDefault();
          this.removeNew(removeNew.dataset.imagePickerRemoveNew);
        }
      });

      this.input.addEventListener('change', () => {
        this.addFiles(Array.from(this.input.files || []));
      });

      ['dragenter', 'dragover'].forEach((eventName) => {
        this.dropzone.addEventListener(eventName, (event) => {
          event.preventDefault();
          event.stopPropagation();
          this.dropzone.classList.add('is-dragging');
        });
      });

      ['dragleave', 'drop'].forEach((eventName) => {
        this.dropzone.addEventListener(eventName, (event) => {
          event.preventDefault();
          event.stopPropagation();
          this.dropzone.classList.remove('is-dragging');
        });
      });

      this.dropzone.addEventListener('drop', (event) => {
        this.addFiles(Array.from(event.dataTransfer?.files || []));
      });
    }

    renderExistingImages() {
      this.existingImages.forEach((image) => {
        const item = this.createPreviewItem(image.url, image.name, 'Current image');
        item.dataset.imagePickerExisting = 'true';
        item.dataset.existingKey = image.key;

        const actions = item.querySelector('.sinetec-image-picker__actions');
        if (!this.multiple) actions.appendChild(this.createAction('replace'));
        if (this.removable) actions.appendChild(this.createAction('remove-existing'));

        this.grid.insertBefore(item, this.dropzone);
      });
    }

    addFiles(incomingFiles) {
      this.clearError();
      const validFiles = [];
      const errors = [];

      incomingFiles.forEach((file) => {
        const validationError = this.validate(file);
        if (validationError) errors.push(validationError);
        else validFiles.push(file);
      });

      if (!this.multiple) {
        if (validFiles.length) this.files = [validFiles[0]];
      } else {
        const existingKeys = new Set(this.files.map(fileKey));
        const remainingSlots = Math.max(this.maxFiles - this.activeExistingCount() - this.files.length, 0);
        const additions = validFiles
          .filter((file) => !existingKeys.has(fileKey(file)))
          .slice(0, remainingSlots);

        this.files = this.files.concat(additions);
        if (validFiles.length > additions.length) errors.push(`You can select up to ${this.maxFiles} images.`);
      }

      this.syncInput();
      this.renderNewFiles();
      this.refresh();

      if (errors.length) this.showError([...new Set(errors)].join(' '));
    }

    validate(file) {
      if (!file.type.startsWith('image/')) return `${file.name} is not an image.`;

      const acceptRules = (this.input.accept || '')
        .split(',')
        .map((rule) => rule.trim().toLowerCase())
        .filter(Boolean);
      const extension = `.${file.name.split('.').pop().toLowerCase()}`;
      const mimeType = file.type.toLowerCase();
      const accepted = !acceptRules.length || acceptRules.some((rule) => {
        if (rule.endsWith('/*')) return mimeType.startsWith(rule.slice(0, -1));
        return rule === mimeType || rule === extension;
      });

      if (!accepted) return `${file.name} has an unsupported format.`;
      if (file.size > this.maxSize) return `${file.name} exceeds ${formatBytes(this.maxSize)}.`;

      return null;
    }

    syncInput() {
      if (typeof DataTransfer === 'undefined') return;
      const transfer = new DataTransfer();
      this.files.forEach((file) => transfer.items.add(file));
      this.input.files = transfer.files;
    }

    renderNewFiles() {
      this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
      this.objectUrls = [];
      this.grid.querySelectorAll('[data-image-picker-new]').forEach((item) => item.remove());

      this.files.forEach((file) => {
        const key = fileKey(file);
        const objectUrl = URL.createObjectURL(file);
        this.objectUrls.push(objectUrl);

        const item = this.createPreviewItem(objectUrl, file.name, formatBytes(file.size));
        item.dataset.imagePickerNew = key;
        const actions = item.querySelector('.sinetec-image-picker__actions');
        if (!this.multiple) actions.appendChild(this.createAction('replace'));

        const removeAction = this.createAction('remove-new');
        removeAction.dataset.imagePickerRemoveNew = key;
        actions.appendChild(removeAction);
        this.grid.insertBefore(item, this.dropzone);
      });
    }

    createPreviewItem(url, name, detail) {
      const item = document.createElement('article');
      item.className = 'sinetec-image-picker__item';
      item.innerHTML = `
        <div class="sinetec-image-picker__preview">
          <img alt="Image preview">
          <div class="sinetec-image-picker__actions"></div>
        </div>
        <div class="sinetec-image-picker__meta">
          <span></span>
          <small></small>
        </div>`;
      item.querySelector('img').src = url;
      item.querySelector('.sinetec-image-picker__meta span').textContent = name;
      item.querySelector('.sinetec-image-picker__meta span').title = name;
      item.querySelector('.sinetec-image-picker__meta small').textContent = detail;
      return item;
    }

    createAction(type) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `sinetec-image-picker__action${type.startsWith('remove') ? ' is-danger' : ''}`;

      if (type === 'replace') {
        button.dataset.imagePickerReplace = 'true';
        button.title = button.ariaLabel = 'Replace image';
        button.innerHTML = '<iconify-icon icon="mdi:image-edit-outline"></iconify-icon>';
      } else if (type === 'remove-existing') {
        button.dataset.imagePickerRemoveExisting = 'true';
        button.title = button.ariaLabel = 'Remove image';
        button.innerHTML = '<iconify-icon icon="mdi:delete-outline"></iconify-icon>';
      } else {
        button.dataset.imagePickerRemoveNew = '';
        button.title = button.ariaLabel = 'Remove image';
        button.innerHTML = '<iconify-icon icon="mdi:delete-outline"></iconify-icon>';
      }

      return button;
    }

    removeNew(key) {
      this.files = this.files.filter((file) => fileKey(file) !== key);
      this.syncInput();
      this.renderNewFiles();
      this.refresh();
      this.clearError();
    }

    removeExisting(item) {
      if (!item) return;
      item.dataset.removed = 'true';
      item.remove();

      if (this.multiple) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = `${this.removeName.replace(/\[\]$/, '')}[]`;
        hidden.value = item.dataset.existingKey || '';
        hidden.dataset.imagePickerRemovedExisting = 'true';
        this.root.appendChild(hidden);
      } else if (this.removeInput) {
        this.removeInput.value = '1';
      }

      this.refresh();
    }

    activeExistingCount() {
      return this.root.querySelectorAll('[data-image-picker-existing]:not([data-removed="true"])').length;
    }

    refresh() {
      const existingItems = Array.from(this.root.querySelectorAll('[data-image-picker-existing]:not([data-removed="true"])'));
      const hasNew = this.files.length > 0;

      if (!this.multiple) existingItems.forEach((item) => item.classList.toggle('is-replaced', hasNew));

      const total = this.activeExistingCount() + this.files.length;
      this.dropzone.hidden = this.multiple ? total >= this.maxFiles : total > 0;
      this.input.required = this.required && total === 0;
    }

    showError(message) {
      this.root.classList.add('has-error');
      this.error.textContent = message;
      this.error.style.display = 'block';
    }

    clearError() {
      this.root.classList.remove('has-error');
      this.input.classList.remove('is-invalid');
      this.error.textContent = '';
      this.error.style.display = 'none';
    }

    destroy() {
      this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
      this.objectUrls = [];
      delete this.input.dataset.imagePickerInitialized;
      delete this.input.imagePicker;
    }
  }

  window.ImagePicker = {
    auto(context) {
      const scope = context?.jquery ? context.get(0) : (context || document);
      if (!scope) return;

      const inputs = [];
      if (scope.matches?.(INPUT_SELECTOR)) inputs.push(scope);
      scope.querySelectorAll?.(INPUT_SELECTOR).forEach((input) => inputs.push(input));

      inputs.forEach((input) => {
        if (input.dataset.imagePickerInitialized === 'true') return;
        input.imagePicker = new SinetecImagePicker(input);
      });
    },

    destroy(context) {
      const scope = context?.jquery ? context.get(0) : (context || document);
      if (!scope) return;
      scope.querySelectorAll?.(INPUT_SELECTOR).forEach((input) => input.imagePicker?.destroy());
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.ImagePicker.auto(document));
  } else {
    window.ImagePicker.auto(document);
  }
})(window, document);
