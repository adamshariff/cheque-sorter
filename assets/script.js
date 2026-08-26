document.addEventListener('DOMContentLoaded', function () {
    var filterForm = document.querySelector('.filters');
    var sideFilter = document.getElementById('sideFilter');
    var classificationFilter = document.getElementById('classificationFilter');
    var preview = document.getElementById('imagePreview');
    var previewImage = document.getElementById('lightboxImage');
    var previewTitle = document.getElementById('lightboxTitle');
    var previewClose = document.getElementById('lightboxClose');

    function submitFilterForm() {
        if (!filterForm) {
            return;
        }

        filterForm.submit();
    }

    function openPreview(source, alt, meta) {
        if (!preview || !previewImage || !previewTitle) {
            return;
        }

        previewImage.src = source;
        previewImage.alt = alt || 'Cheque preview';
        previewTitle.textContent = meta || alt || 'Cheque preview';
        preview.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closePreview() {
        if (!preview || !previewImage) {
            return;
        }

        preview.hidden = true;
        previewImage.src = '';
        document.body.style.overflow = '';
    }

    if (sideFilter) {
        sideFilter.addEventListener('change', submitFilterForm);
    }

    if (classificationFilter) {
        classificationFilter.addEventListener('change', submitFilterForm);
    }

    document.querySelectorAll('[data-preview-src]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            openPreview(
                trigger.getAttribute('data-preview-src'),
                trigger.getAttribute('data-preview-alt'),
                trigger.getAttribute('data-preview-meta')
            );
        });
    });

    if (preview) {
        preview.addEventListener('click', function (event) {
            var target = event.target;

            if (target && target.getAttribute('data-close-preview') === 'true') {
                closePreview();
            }
        });
    }

    if (previewClose) {
        previewClose.addEventListener('click', closePreview);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closePreview();
        }
    });
});
