// Click-to-sort headings for the wiki tables the server marks with
// data-tablesort (see WikiMarkdownRenderer::markSortableTables). Numbers sort
// as numbers, everything else as text; a second click reverses the order.
function sortTable(table, columnIndex, descending) {
    const body = table.tBodies[0] ?? table;
    const rows = Array.from(body.rows).filter((row) => row.cells.length > columnIndex && row.querySelector('td'));
    const cellText = (row) => row.cells[columnIndex].textContent.trim();
    const asNumber = (text) => Number.parseFloat(text.replace(/[,\s]/g, ''));
    const allNumeric = rows.every((row) => cellText(row) === '' || !Number.isNaN(asNumber(cellText(row))));

    rows.sort((a, b) => {
        const left = cellText(a);
        const right = cellText(b);
        const order = allNumeric
            ? (Number.isNaN(asNumber(left)) ? -Infinity : asNumber(left)) - (Number.isNaN(asNumber(right)) ? -Infinity : asNumber(right))
            : left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });

        return descending ? -order : order;
    });

    rows.forEach((row) => body.appendChild(row));
}

function enableTableSorting(root = document) {
    root.querySelectorAll('table[data-tablesort]:not([data-tablesort-ready])').forEach((table) => {
        table.setAttribute('data-tablesort-ready', '1');
        const headerRow = table.tHead?.rows[0] ?? table.rows[0];

        Array.from(headerRow.cells).forEach((heading, index) => {
            heading.style.cursor = 'pointer';
            heading.setAttribute('aria-sort', 'none');
            heading.addEventListener('click', () => {
                const descending = heading.getAttribute('aria-sort') === 'ascending';

                Array.from(headerRow.cells).forEach((other) => other.setAttribute('aria-sort', 'none'));
                heading.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
                sortTable(table, index, descending);
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', () => enableTableSorting());
document.addEventListener('livewire:navigated', () => enableTableSorting());
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook?.('morphed', () => enableTableSorting());
});
