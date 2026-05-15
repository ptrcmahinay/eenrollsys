/**
 * Lightweight client-side data table enhancer.
 * Adds: search, per-column filter, sortable headers, pagination, and
 * responsive card-style rendering on small screens.
 *
 * Markup contract:
 *   <div class="dt" data-dt-page-size="10" data-dt-bulk-delete-url="/delete-endpoint" data-dt-bulk-id-field="id">
 *     <div class="dt-toolbar"></div>     (optional placeholder; auto-created)
 *     <div class="table-wrap">
 *       <table>
 *         <thead>
 *           <tr>
 *             <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
 *             <th data-dt-key="name" data-dt-filter="text">Name</th>
 *             <th data-dt-key="role" data-dt-filter="select">Role</th>
 *             <th data-dt-no-sort>Action</th>
 *           </tr>
 *         </thead>
 *         <tbody>
 *           <tr data-dt-row-id="123">
 *             <td><input type="checkbox" class="dt-bulk-row" value="123" aria-label="Select row"></td>
 *             <td>John Doe</td> ...
 *           </tr>
 *         </tbody>
 *       </table>
 *     </div>
 *     <div class="dt-footer"></div>      (optional placeholder; auto-created)
 *   </div>
 *
 * Bulk-select attributes on the .dt wrapper:
 *   data-dt-bulk-delete-url  - POST endpoint for bulk deletion (enables bulk select)
 *   data-dt-bulk-id-field    - hidden field name for each id (default: "ids")
 *   data-dt-bulk-confirm     - confirmation message (default: "Delete N selected records?")
 *   data-dt-bulk-action      - POST "action" value (default: "bulk_delete")
 */
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function getCellText(td) {
        if (!td) return '';
        var override = td.getAttribute('data-dt-value');
        if (override !== null) return override;
        return (td.textContent || '').trim();
    }

    function compare(a, b) {
        var na = parseFloat(a.replace(/[^0-9.\-]/g, ''));
        var nb = parseFloat(b.replace(/[^0-9.\-]/g, ''));
        var bothNum = !isNaN(na) && !isNaN(nb) && a.replace(/[^0-9.\-]/g, '') !== '' && b.replace(/[^0-9.\-]/g, '') !== '';
        if (bothNum) return na - nb;
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }

    function initTable(root) {
        var table = $('table', root);
        if (!table) return;
        var thead = $('thead', table);
        var tbody = $('tbody', table);
        if (!thead || !tbody) return;

        var headers = $$('th', thead);
        var rows = $$('tr', tbody).filter(function (tr) {
            // Skip "empty" placeholder rows (single colspan cell)
            return !tr.querySelector('td.empty');
        });
        var emptyRow = $('tr td.empty', tbody);

        if (!rows.length) {
            // Still set up label-cells for any responsive empty-state.
            decorateLabels(rows, headers);
            return;
        }

        // Decorate cells with data-label attributes for responsive view.
        decorateLabels(rows, headers);

        var pageSize = parseInt(root.getAttribute('data-dt-page-size') || '10', 10);

        // --- Bulk-select setup ---
        var bulkDeleteUrl = root.getAttribute('data-dt-bulk-delete-url') || '';
        var bulkIdField = root.getAttribute('data-dt-bulk-id-field') || 'ids';
        var bulkConfirm = root.getAttribute('data-dt-bulk-confirm') || '';
        var bulkAction = root.getAttribute('data-dt-bulk-action') || 'bulk_delete';
        var bulkEnabled = bulkDeleteUrl !== '';
        var selectAllCb = null;
        var rowCheckboxes = [];

        if (bulkEnabled) {
            // Add checkbox header column
            var cbTh = document.createElement('th');
            cbTh.setAttribute('data-dt-no-sort', '');
            cbTh.setAttribute('data-dt-no-export', '');
            cbTh.style.width = '44px';
            selectAllCb = document.createElement('input');
            selectAllCb.type = 'checkbox';
            selectAllCb.className = 'dt-bulk-select-all';
            selectAllCb.setAttribute('aria-label', 'Select all');
            cbTh.appendChild(selectAllCb);
            thead.querySelector('tr').insertBefore(cbTh, thead.querySelector('tr').firstChild);
            headers = $$('th', thead); // refresh

            // Add checkbox to each row
            rows.forEach(function (tr) {
                var rowId = tr.getAttribute('data-dt-row-id') || '';
                var cbTd = document.createElement('td');
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'dt-bulk-row';
                cb.value = rowId;
                cb.setAttribute('aria-label', 'Select row');
                cbTd.appendChild(cb);
                tr.insertBefore(cbTd, tr.firstChild);
                rowCheckboxes.push(cb);
            });
        }
        var state = {
            search: '',
            filters: {}, // headerIndex -> string
            sortIndex: -1,
            sortDir: 1,
            page: 1,
            pageSize: pageSize > 0 ? pageSize : 10
        };

        // Build toolbar
        var toolbar = $('.dt-toolbar', root);
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.className = 'dt-toolbar';
            root.insertBefore(toolbar, root.firstChild);
        }

        var searchWrap = document.createElement('div');
        searchWrap.className = 'dt-search';
        searchWrap.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">search</span>'
            + '<input type="search" placeholder="Search..." aria-label="Search table">';
        var searchInput = searchWrap.querySelector('input');
        toolbar.appendChild(searchWrap);

        var filtersWrap = document.createElement('div');
        filtersWrap.className = 'dt-filters';
        toolbar.appendChild(filtersWrap);

        var exportBtn = document.createElement('button');
        exportBtn.type = 'button';
        exportBtn.className = 'btn small secondary dt-export';
        exportBtn.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">download</span> Export CSV';
        exportBtn.addEventListener('click', function () {
            var visibleHeaderIdx = [];
            headers.forEach(function (th, i) {
                if (th.getAttribute('data-dt-no-export') !== null) return;
                if ((th.textContent || '').trim() === '' && th.hasAttribute('data-dt-no-sort')) return;
                visibleHeaderIdx.push(i);
            });
            var csvRows = [];
            csvRows.push(visibleHeaderIdx.map(function(i){
                var clone = headers[i].cloneNode(true);
                var ind = clone.querySelector('.dt-sort-indicator'); if (ind) ind.remove();
                return csvCell((clone.textContent||'').trim());
            }).join(','));
            getFiltered().forEach(function (tr) {
                csvRows.push(visibleHeaderIdx.map(function(i){ return csvCell(getCellText(tr.children[i])); }).join(','));
            });
            var blob = new Blob([csvRows.join('\n')], {type:'text/csv;charset=utf-8;'});
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = (document.title || 'table').replace(/[^a-z0-9]+/gi,'_').toLowerCase() + '.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        });
        function csvCell(v){ v = String(v==null?'':v); if (/[",\n]/.test(v)) v = '"'+v.replace(/"/g,'""')+'"'; return v; }
        toolbar.appendChild(exportBtn);


        // Per-column filters (select-only — collected from data)
        headers.forEach(function (th, idx) {
            var filterType = th.getAttribute('data-dt-filter');
            if (filterType !== 'select') return;
            var values = {};
            rows.forEach(function (tr) {
                var v = getCellText(tr.children[idx]);
                if (v !== '') values[v] = true;
            });
            var keys = Object.keys(values).sort(function (a, b) { return compare(a, b); });
            if (!keys.length) return;
            var sel = document.createElement('select');
            sel.className = 'dt-filter-select';
            sel.setAttribute('aria-label', 'Filter ' + (th.textContent || '').trim());
            var opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = 'All ' + (th.textContent || '').trim();
            sel.appendChild(opt0);
            keys.forEach(function (k) {
                var o = document.createElement('option');
                o.value = k;
                o.textContent = k;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                if (sel.value === '') delete state.filters[idx];
                else state.filters[idx] = sel.value;
                state.page = 1;
                render();
            });
            filtersWrap.appendChild(sel);
        });

        // Sortable headers
        headers.forEach(function (th, idx) {
            if (th.hasAttribute('data-dt-no-sort')) return;
            th.classList.add('dt-sortable');
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');
            var indicator = document.createElement('span');
            indicator.className = 'dt-sort-indicator';
            indicator.textContent = '';
            th.appendChild(indicator);
            var trigger = function () {
                if (state.sortIndex === idx) {
                    state.sortDir = -state.sortDir;
                } else {
                    state.sortIndex = idx;
                    state.sortDir = 1;
                }
                render();
            };
            th.addEventListener('click', trigger);
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); trigger(); }
            });
        });

        // Footer
        var footer = $('.dt-footer', root);
        if (!footer) {
            footer = document.createElement('div');
            footer.className = 'dt-footer';
            root.appendChild(footer);
        }

        searchInput.addEventListener('input', function () {
            state.search = searchInput.value.trim().toLowerCase();
            state.page = 1;
            render();
        });

        // --- Bulk select event handlers ---
        var actionBar = null;
        if (bulkEnabled) {
            // Create action bar
            actionBar = document.createElement('div');
            actionBar.className = 'dt-bulk-bar';
            actionBar.style.display = 'none';
            actionBar.innerHTML = '<span class="dt-bulk-count">0 selected</span>'
                + '<button type="button" class="btn small secondary dt-bulk-deselect">Deselect All</button>'
                + '<form method="post" class="inline-form dt-bulk-form" style="display:inline;">'
                + '<button type="submit" class="btn small danger dt-bulk-delete-btn"><span class="material-symbols-outlined" style="font-size:16px;">delete</span> Delete Selected</button>'
                + '</form>';
            root.insertBefore(actionBar, root.firstChild.nextSibling);

            var bulkCount = actionBar.querySelector('.dt-bulk-count');
            var bulkDeselect = actionBar.querySelector('.dt-bulk-deselect');
            var bulkForm = actionBar.querySelector('.dt-bulk-form');

            function getCheckedIds() {
                var ids = [];
                rowCheckboxes.forEach(function (cb) {
                    if (cb.checked) ids.push(cb.value);
                });
                return ids;
            }

            function updateBar() {
                var ids = getCheckedIds();
                var n = ids.length;
                bulkCount.textContent = n + ' selected';
                actionBar.style.display = n > 0 ? '' : 'none';

                if (selectAllCb) {
                    selectAllCb.checked = n > 0 && n === rowCheckboxes.length;
                }

                // Update hidden fields in form
                bulkForm.innerHTML = '<input type="hidden" name="action" value="' + bulkAction + '">';
                ids.forEach(function (id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = bulkIdField + '[]';
                    inp.value = id;
                    bulkForm.appendChild(inp);
                });
                var delBtn = document.createElement('button');
                delBtn.type = 'submit';
                delBtn.className = 'btn small danger';
                delBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">delete</span> Delete Selected';
                bulkForm.appendChild(delBtn);
            }

            function deselectAll() {
                rowCheckboxes.forEach(function (cb) { cb.checked = false; });
                updateBar();
            }

            bulkDeselect.addEventListener('click', deselectAll);

            bulkForm.addEventListener('submit', function (e) {
                var ids = getCheckedIds();
                var msg = bulkConfirm || 'Delete ' + ids.length + ' selected records?';
                if (!confirm(msg)) {
                    e.preventDefault();
                } else {
                    bulkForm.action = bulkDeleteUrl;
                }
            });

            // Select all handler — selects ALL rows (across pages), not just visible
            if (selectAllCb) {
                selectAllCb.addEventListener('change', function () {
                    rowCheckboxes.forEach(function (cb) {
                        cb.checked = selectAllCb.checked;
                    });
                    updateBar();
                });
            }

            // Row checkbox handlers
            rowCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', updateBar);
            });
        }

        function getSelectedRows() {
            var selected = [];
            rowCheckboxes.forEach(function (cb) {
                if (cb.checked) selected.push(cb.closest('tr'));
            });
            return selected;
        }

        function getFiltered() {
            return rows.filter(function (tr) {
                if (state.search) {
                    var text = (tr.textContent || '').toLowerCase();
                    if (text.indexOf(state.search) === -1) return false;
                }
                for (var k in state.filters) {
                    if (!state.filters.hasOwnProperty(k)) continue;
                    var cell = tr.children[parseInt(k, 10)];
                    if (getCellText(cell) !== state.filters[k]) return false;
                }
                return true;
            });
        }

        function render() {
            // Update sort indicators
            headers.forEach(function (th, idx) {
                var ind = th.querySelector('.dt-sort-indicator');
                if (!ind) return;
                if (idx === state.sortIndex) ind.textContent = state.sortDir > 0 ? ' ▲' : ' ▼';
                else ind.textContent = '';
            });

            var list = getFiltered();

            if (state.sortIndex >= 0) {
                list = list.slice().sort(function (a, b) {
                    var av = getCellText(a.children[state.sortIndex]);
                    var bv = getCellText(b.children[state.sortIndex]);
                    return compare(av, bv) * state.sortDir;
                });
            }

            var total = list.length;
            var pages = Math.max(1, Math.ceil(total / state.pageSize));
            if (state.page > pages) state.page = pages;
            var start = (state.page - 1) * state.pageSize;
            var pageRows = list.slice(start, start + state.pageSize);

            // Hide all original rows; append the page in order
            rows.forEach(function (tr) { tr.style.display = 'none'; });
            pageRows.forEach(function (tr) {
                tr.style.display = '';
                tbody.appendChild(tr); // keep order
            });

            // Empty state
            var existingEmpty = tbody.querySelector('tr.dt-empty');
            var colSpanForEmpty = headers.length;
            if (total === 0) {
                if (!existingEmpty) {
                    var tr = document.createElement('tr');
                    tr.className = 'dt-empty';
                    var td = document.createElement('td');
                    td.colSpan = colSpanForEmpty;
                    td.className = 'empty';
                    td.textContent = 'No matching records.';
                    tr.appendChild(td);
                    tbody.appendChild(tr);
                }
            } else if (existingEmpty) {
                existingEmpty.remove();
            }

            // Footer
            footer.innerHTML = '';
            var info = document.createElement('div');
            info.className = 'dt-info';
            if (total === 0) {
                info.textContent = 'No records';
            } else {
                info.textContent = 'Showing ' + (start + 1) + '–' + Math.min(start + state.pageSize, total) + ' of ' + total;
            }
            footer.appendChild(info);

            if (pages > 1) {
                var pager = document.createElement('div');
                pager.className = 'dt-pager';
                var prev = document.createElement('button');
                prev.type = 'button';
                prev.className = 'btn small secondary';
                prev.textContent = 'Prev';
                prev.disabled = state.page <= 1;
                prev.addEventListener('click', function () { state.page--; render(); });
                var label = document.createElement('span');
                label.className = 'dt-page-label';
                label.textContent = 'Page ' + state.page + ' of ' + pages;
                var next = document.createElement('button');
                next.type = 'button';
                next.className = 'btn small secondary';
                next.textContent = 'Next';
                next.disabled = state.page >= pages;
                next.addEventListener('click', function () { state.page++; render(); });
                pager.appendChild(prev);
                pager.appendChild(label);
                pager.appendChild(next);
                footer.appendChild(pager);
            }

            // Update bulk bar after render (selection persists across page/filter changes)
            if (bulkEnabled && actionBar) {
                updateBar();
            }
        }

        // Hide pre-existing empty placeholder if present, we manage our own.
        if (emptyRow) emptyRow.parentElement.style.display = 'none';

        render();
    }

    function decorateLabels(rows, headers) {
        var labels = headers.map(function (th) {
            // Strip the sort indicator if any
            var clone = th.cloneNode(true);
            var ind = clone.querySelector('.dt-sort-indicator');
            if (ind) ind.remove();
            return (clone.textContent || '').trim();
        });
        rows.forEach(function (tr) {
            $$('td', tr).forEach(function (td, i) {
                if (!td.hasAttribute('data-label') && labels[i]) {
                    td.setAttribute('data-label', labels[i]);
                }
            });
        });
    }

    function init() {
        $$('.dt').forEach(initTable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
