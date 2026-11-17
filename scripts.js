/* NIVMP - Nextcloud Instances Versions Monitoring Page
   Filter + sortable table, no dependency. The page stays fully readable without JavaScript. */
(function () {
	'use strict';

	var table = document.getElementById('instances');
	if (!table || !table.tBodies.length) { return; }

	var tbody = table.tBodies[0];
	var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);
	var filter = document.getElementById('filter');
	var empty = document.getElementById('no-results');
	var sortSelect = document.getElementById('sort');
	var sortLabel = sortSelect ? sortSelect.parentNode : null;

	function rows() {
		return Array.prototype.slice.call(tbody.rows);
	}

	/* ---------- Filter ---------- */
	function applyFilter() {
		var needle = (filter.value || '').trim().toLowerCase();
		var shown = 0;
		rows().forEach(function (row) {
			var match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
			row.hidden = !match;
			if (match) { shown++; }
		});
		if (empty) { empty.hidden = shown !== 0; }
	}

	if (filter) {
		filter.addEventListener('input', applyFilter);
	}

	/* ---------- Sort ---------- */
	// Cells carry a data-sort value: numeric columns compare numbers,
	// the others compare text. Empty values (N/A) always go last.
	function sortBy(col, direction) {
		var numeric = headers[col].getAttribute('data-type') === 'num';
		var factor = direction === 'ascending' ? 1 : -1;

		var items = rows().map(function (row, index) {
			var raw = row.cells[col].getAttribute('data-sort');
			if (raw === null) { raw = row.cells[col].textContent.trim().toLowerCase(); }
			return { row: row, index: index, raw: raw, empty: raw === '' };
		});

		items.sort(function (a, b) {
			if (a.empty !== b.empty) { return a.empty ? 1 : -1; }
			var result = 0;
			if (!a.empty) {
				if (numeric) {
					result = (parseFloat(a.raw) - parseFloat(b.raw)) * factor;
				} else {
					result = a.raw.localeCompare(b.raw, undefined, { numeric: true }) * factor;
				}
			}
			return result || a.index - b.index;
		});

		items.forEach(function (item) { tbody.appendChild(item.row); });

		headers.forEach(function (th, i) {
			if (i === col) {
				th.setAttribute('aria-sort', direction);
			} else {
				th.removeAttribute('aria-sort');
			}
		});

		if (sortSelect) { sortSelect.value = col + ':' + direction; }
	}

	headers.forEach(function (th, col) {
		var button = th.querySelector('button');
		if (!button) { return; }
		button.addEventListener('click', function () {
			var next = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
			sortBy(col, next);
		});
	});

	/* ---------- Sort menu (used on phones, where headers are hidden) ---------- */
	if (sortSelect && sortLabel) {
		var asc = sortLabel.getAttribute('data-asc') || 'asc';
		var desc = sortLabel.getAttribute('data-desc') || 'desc';

		var placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = '—';
		sortSelect.appendChild(placeholder);

		headers.forEach(function (th, col) {
			var button = th.querySelector('button');
			if (!button) { return; }
			var name = button.firstChild.textContent.trim();
			[['ascending', asc], ['descending', desc]].forEach(function (pair) {
				var option = document.createElement('option');
				option.value = col + ':' + pair[0];
				option.textContent = name + ' (' + pair[1] + ')';
				sortSelect.appendChild(option);
			});
		});

		sortSelect.addEventListener('change', function () {
			if (!sortSelect.value) { return; }
			var parts = sortSelect.value.split(':');
			sortBy(parseInt(parts[0], 10), parts[1]);
		});
	}
})();
