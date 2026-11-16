(function () {
	'use strict';

	const copy = document.querySelector('[data-nttc-copy]');
	if (copy) {
		const field = document.getElementById(copy.dataset.nttcCopy);
		const status = document.querySelector('[data-nttc-copy-status]');
		copy.addEventListener('click', async function () {
			let copied = false;
			try {
				await navigator.clipboard.writeText(field.value);
				copied = true;
			} catch (error) {
				field.focus();
				field.select();
				try {
					copied = document.execCommand('copy');
				} catch (copyError) {
					copied = false;
				}
			}

			status.textContent = copied ? 'آدرس کپی شد.' : 'آدرس انتخاب شد. از دستور کپی مرورگر استفاده کن.';
			copy.textContent = copied ? 'کپی شد' : 'کپی آدرس';
		});
	}

	document.querySelectorAll('[data-nttc-confirm]').forEach(function (button) {
		if (button.dataset.nttcConfirm) {
			button.form.addEventListener('submit', function (event) {
				if (!window.confirm(button.dataset.nttcConfirm)) {
					event.preventDefault();
				}
			});
		}
	});
}());
