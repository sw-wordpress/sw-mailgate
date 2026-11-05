/* Progressive enhancement: the complete setup guide remains readable without JavaScript. */
document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('[data-swmg-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			const input = button.previousElementSibling;
			input.focus();
			input.select();
			if (typeof navigator !== 'undefined' && navigator.clipboard) {
				navigator.clipboard.writeText(input.value).then(function () {
					button.textContent = button.dataset.copiedLabel;
				}).catch(function () { /* The selected URL remains available for manual copying. */ });
			}
		});
	});
	document.querySelectorAll('.swmg-oauth-help').forEach(function (details) {
		if (typeof HTMLDialogElement === 'undefined') {
			return;
		}
		const summary = details.querySelector('summary');
		const content = details.querySelector('.swmg-oauth-instructions');
		const dialog = document.createElement('dialog');
		dialog.className = 'swmg-oauth-dialog';
		dialog.setAttribute('aria-labelledby', 'swmg-oauth-help-title');
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'button swmg-oauth-close';
		close.textContent = details.dataset.closeLabel;
		dialog.append(close, content);
		document.body.append(dialog);
		summary.setAttribute('aria-haspopup', 'dialog');
		summary.addEventListener('click', function (event) {
			event.preventDefault();
			dialog.showModal();
		});
		close.addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('close', function () { summary.focus(); });
	});
});
