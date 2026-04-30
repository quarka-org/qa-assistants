/**
 * QA Table 強化版テーブルライブラリ
 * 
 * 機能:
 * - ページネーション（改善版）
 * - ソート
 * - 複数フィルタ
 * - 日本語フィルタラベル
 * - 非表示カラム
 * - エクスポート（CSV/JSON）
 * - 行選択
 */

// フィルタタイプ定数
const QA_FILTER_TYPES = {
    CONTAINS: 'contains',
    NOT_CONTAINS: 'not_contains',
    EQUALS: 'equals',
    NOT_EQUALS: 'not_equals',
    STARTS_WITH: 'starts_with',
    ENDS_WITH: 'ends_with',
    GREATER_THAN: 'greater_than',
    LESS_THAN: 'less_than',
    IS_EMPTY: 'is_empty',
    IS_NOT_EMPTY: 'is_not_empty'
};

// カラムタイプ定数
const QA_COLUMN_TYPES = {
    STRING: 'string',
    NUMBER: 'number',
    INTEGER: 'integer',
    FLOAT: 'float',
    DATE: 'date',
    DATETIME: 'datetime',
    BOOLEAN: 'boolean',
    CURRENCY: 'currency',
    PERCENTAGE: 'percentage',
    DURATION: 'duration',
    FILESIZE: 'filesize',
    LINK: 'link',
    TIMESTAMP: 'timestamp',
    HTML: 'html',
    CHECK: 'check'
};

// グローバルオブジェクト
const qaTable = {
    instances: {},
    init: function(selector, data = [], columns = [], options = {}) {
        const instance = new QATable(selector, data, columns, options);
        // インスタンスを保存
        const instanceId = selector.replace(/[^a-zA-Z0-9]/g, '_');
        this.instances[instanceId] = instance;
        return instance;
    },
    createTable: function(selector, columns = [], options = {}) {
        return this.init(selector, [], columns, options);
    }
};

/**
 * QATable クラス
 */
class QATable {
    constructor(selector, data = [], columns = [], options = {}) {
        const defaultOptions = {
            pagination: false,
            perPage: 10,
            sortable: false,
            filtering: false,
            exportable: false,
            rowSelection: false, // 後方互換性のために残す
            maxHeight: null,
            initialSort: null,
            stickyHeader: false // 新規追加：デフォルトOFF
        };
        
        this.container = typeof selector === 'string' 
            ? document.querySelector(selector) 
            : selector;
        
        if (!this.container) {
            throw new Error(this.__('Container not found: ') + selector);
        }
        
        // 2次元配列かどうかを判定
        const is2DArray = Array.isArray(data) && data.length > 0 && Array.isArray(data[0]);
        
        // データを保存（2次元配列の場合はコピーせずに参照を保持してメモリ使用量を削減）
        this.data = Array.isArray(data) ? data : [];
        this.columns = Array.isArray(columns) ? columns : [];
        this.options = { ...defaultOptions, ...options };
        
        if (this.options.stickyHeader) {
            const style = document.createElement('style');
            // topの値は0pxだと後ろの文字がはみ出る現象が発生したため、-2pxに調整
            style.textContent = `
                .qa-table thead th {
                    position: sticky;
                    top: -2px;
                    z-index: 10;
                }
            `;
            document.head.appendChild(style);
        }
        
        this.columnSelections = {};
        
        // 2次元配列の場合はコピーせずに参照を保持してメモリ使用量を削減
        this.filteredData = is2DArray ? this.data : [...this.data];
        this.currentPage = 1;
        this.perPage = this.options.perPage;
        this.sortState = [];
        this.filters = [];
        this.selectedRows = new Set();
        
        const stringFilterOptions = [
            { value: QA_FILTER_TYPES.CONTAINS, label: this.__('Contains') },
            { value: QA_FILTER_TYPES.NOT_CONTAINS, label: this.__('Does not contain') },
            { value: QA_FILTER_TYPES.EQUALS, label: this.__('Equals') },
            { value: QA_FILTER_TYPES.NOT_EQUALS, label: this.__('Not equals') },
            { value: QA_FILTER_TYPES.STARTS_WITH, label: this.__('Starts with') },
            { value: QA_FILTER_TYPES.ENDS_WITH, label: this.__('Ends with') },
            { value: QA_FILTER_TYPES.IS_EMPTY, label: this.__('Is empty') },
            { value: QA_FILTER_TYPES.IS_NOT_EMPTY, label: this.__('Is not empty') }
        ];
        const numericFilterOptions = [
            { value: QA_FILTER_TYPES.GREATER_THAN, label: this.__('Greater than') },
            { value: QA_FILTER_TYPES.LESS_THAN, label: this.__('Less than') },
            { value: QA_FILTER_TYPES.EQUALS, label: this.__('Equals') },
            { value: QA_FILTER_TYPES.NOT_EQUALS, label: this.__('Not equals') },
            { value: QA_FILTER_TYPES.IS_EMPTY, label: this.__('Is empty') },
            { value: QA_FILTER_TYPES.IS_NOT_EMPTY, label: this.__('Is not empty') }
        ];
        const dateFilterOptions = [
            { value: QA_FILTER_TYPES.GREATER_THAN, label: this.__('After') },
            { value: QA_FILTER_TYPES.LESS_THAN, label: this.__('Before') },
            { value: QA_FILTER_TYPES.EQUALS, label: this.__('Equals') },
            { value: QA_FILTER_TYPES.NOT_EQUALS, label: this.__('Not equals') },
            { value: QA_FILTER_TYPES.IS_EMPTY, label: this.__('Is empty') },
            { value: QA_FILTER_TYPES.IS_NOT_EMPTY, label: this.__('Is not empty') }
        ];
        const booleanFilterOptions = [
            { value: QA_FILTER_TYPES.EQUALS, label: this.__('Equals') },
            { value: QA_FILTER_TYPES.NOT_EQUALS, label: this.__('Not equals') },
            { value: QA_FILTER_TYPES.IS_EMPTY, label: this.__('Is empty') },
            { value: QA_FILTER_TYPES.IS_NOT_EMPTY, label: this.__('Is not empty') }
        ];
        const durationFilterOptions = [
            { value: QA_FILTER_TYPES.GREATER_THAN, label: this.__('Longer than') },
            { value: QA_FILTER_TYPES.LESS_THAN, label: this.__('Shorter than') },
            { value: QA_FILTER_TYPES.EQUALS, label: this.__('Equals') },
            { value: QA_FILTER_TYPES.NOT_EQUALS, label: this.__('Not equals') },
            { value: QA_FILTER_TYPES.IS_EMPTY, label: this.__('Is empty') },
            { value: QA_FILTER_TYPES.IS_NOT_EMPTY, label: this.__('Is not empty') }
        ];
        const defaultFilterOptions = [
            { value: QA_FILTER_TYPES.CONTAINS, label: this.__('Contains') },
            { value: QA_FILTER_TYPES.NOT_CONTAINS, label: this.__('Does not contain') },
            { value: QA_FILTER_TYPES.EQUALS, label: this.__('Equals') },
            { value: QA_FILTER_TYPES.NOT_EQUALS, label: this.__('Not equals') },
            { value: QA_FILTER_TYPES.IS_EMPTY, label: this.__('Is empty') },
            { value: QA_FILTER_TYPES.IS_NOT_EMPTY, label: this.__('Is not empty') }
        ];
        
        this.filterOptionsByType = {
            string: stringFilterOptions,
            link: stringFilterOptions,
            html: stringFilterOptions,
            integer: numericFilterOptions,
            number: numericFilterOptions,
            float: numericFilterOptions,
            currency: numericFilterOptions,
            percentage: numericFilterOptions,
            filesize: numericFilterOptions,
            date: dateFilterOptions,
            datetime: dateFilterOptions,
            timestamp: dateFilterOptions,
            boolean: booleanFilterOptions,
            duration: durationFilterOptions,
            default: defaultFilterOptions
        };
        
        // 初期化処理（旧_init関数の内容）
        this.container.innerHTML = '';
        
        // ローディングスピナーが存在していれば削除
        this.loadingOverlay = null;
        
        const tableContainer = document.createElement('div');
        tableContainer.className = 'qa-table-container';

        const optionContainer = document.createElement('div');
        optionContainer.className = 'qa-table-option-container';
        
        if (this.options.filtering) {
            this._createFilteringUI(optionContainer);
        }
        
        if (this.options.exportable) {
            this._createExportButtons(optionContainer);
        }

		tableContainer.appendChild(optionContainer);
        
        // Create main container for the entire table component
        const mainContainer = document.createElement('div');
        mainContainer.className = 'qa-table-main-container';
        
        // Create a separate container for just the table with scrolling
        const tableScrollContainer = document.createElement('div');
        tableScrollContainer.className = 'qa-table-scroll-container';
        
        // Set table height if specified - only applies to the table itself, not filters/exports
        if (this.options.maxHeight) {
            tableScrollContainer.style.maxHeight = this.options.maxHeight + 'px';
            tableScrollContainer.style.overflowY = 'auto';
        }
        
        const table = document.createElement('table');
        table.className = 'qa-table';
        
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        
        this.columns.forEach(column => {
            if (column.type === 'check') {
                if (!this.columnSelections[column.key]) {
                    this.columnSelections[column.key] = new Set();
                }
            }
        });
        
        this.columns.forEach(column => {
            if (column.hidden === true) {
                return;
            }
            
            const th = document.createElement('th');
            
            // Set column width if specified
            if (column.width) {
                th.style.width = column.width + '%';
            }
            
            if (this.options.sortable && column.sortable !== false) {
                const headerContent = document.createElement('div');
                headerContent.className = 'qa-sort-header';
                
                const headerText = document.createElement('span');
                headerText.textContent = column.label || column.key;
                
                const sortIcon = document.createElement('span');
                sortIcon.className = 'qa-sort-icon';
                
                headerContent.appendChild(headerText);
                headerContent.appendChild(sortIcon);
                th.appendChild(headerContent);
                
                th.addEventListener('click', (e) => {
                    // CtrlキーまたはCommandキーでマルチソート
                    const isMultiSort = e.ctrlKey || e.metaKey;
                    this._handleSort(column.key, isMultiSort);
                });
                
                th.classList.add('qa-sortable');
            } else {
                th.textContent = column.label || column.key;
                th.classList.add('qa-not-sortable'); // Add not-sortable class
            }
            
            headerRow.appendChild(th);
        });
        
        thead.appendChild(headerRow);
        table.appendChild(thead);
        
        const tbody = document.createElement('tbody');
        table.appendChild(tbody);
        
        // Only add the table to the scroll container
        tableScrollContainer.appendChild(table);
        
        // Add the scroll container to the table container
        tableContainer.appendChild(tableScrollContainer);
        
        // Add pagination outside the scroll container but inside the table container
        if (this.options.pagination) {
            const paginationContainer = document.createElement('div');
            paginationContainer.className = 'qa-pagination';
            tableContainer.appendChild(paginationContainer);
        }
        
        // Add the table container to the main container
        mainContainer.appendChild(tableContainer);
        this.container.appendChild(mainContainer);
        
        // Set initial sort if specified
        if (this.options.initialSort) {
            this._handleSort(
                this.options.initialSort.column, 
                false,
                this.options.initialSort.direction || 'asc'
            );
        }
        
        this._renderTable();
        
        // ローディングスピナーを表示（updateDataが呼ばれるまで表示したままにする）
        this.showLoading();
    }
    
    /**
     * Translates a text string
     * @param {string} text - Text to translate
     * @returns {string} - Translated text or original text if translation not found
     */
    __(text) {
        if (window.qaTableL10n && window.qaTableL10n[text]) {
            return window.qaTableL10n[text];
        }
        
        return text;
    }
    
    // テーブル描画
    _renderTable() {
        const table = this.container.querySelector('.qa-table');
        if (!table) return;
        
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        // ソート適用（ソート状態がある場合）
        if (this.sortState.length > 0) {
            this._applySorting();
        }
        
        let displayData = this.filteredData;
        if (this.options.pagination) {
            const startIndex = (this.currentPage - 1) * this.perPage;
            const endIndex = startIndex + this.perPage;
            displayData = this.filteredData.slice(startIndex, endIndex);
        }
        
        // スクロールコンテナを取得
        const scrollContainer = this.container.querySelector('.qa-table-scroll-container');
        
        // データがある場合は通常のスクロール動作を維持
        if (scrollContainer) {
            scrollContainer.style.overflowY = 'auto';
        }
        
        if (displayData.length === 0) {
            const emptyRow = document.createElement('tr');
            
            // テーブルの表示領域の高さを計算（ヘッダーの高さを考慮）
            const tableHeight = this.options.maxHeight ? 
                (typeof this.options.maxHeight === 'number' ? this.options.maxHeight : parseInt(this.options.maxHeight)) : 400;
            
            // テーブルヘッダーの高さを取得（存在する場合）
            const tableHeader = this.container.querySelector('thead');
            const headerHeight = tableHeader ? tableHeader.offsetHeight : 0;
            
            // テーブル本体の表示領域の高さを設定（ヘッダーの高さを考慮）
            const bodyHeight = tableHeight - headerHeight;
            emptyRow.style.height = `${bodyHeight}px`;
            
            // 空データ表示時のみスクロールバーを非表示にする（Chromeでの表示を考慮）
            if (scrollContainer) {
                scrollContainer.style.overflowY = 'hidden';
            }
            
            const emptyCell = document.createElement('td');
            
            // 行選択列を含む全カラム数を計算
            let totalColumnCount = this.columns.filter(col => col.hidden !== true).length;
            
            // 行選択オプションが有効な場合、カラム数に1を追加
            if (this.options.rowSelection) {
                totalColumnCount += 1;
            }
            
            emptyCell.colSpan = totalColumnCount;
            emptyCell.className = 'qa-empty-message';
            
            // 空のデータ配列の場合のメッセージを表示（より視覚的に）
            const noDataContainer = document.createElement('div');
            noDataContainer.className = 'qa-no-data-container';
            
            const noDataIcon = document.createElement('div');
            noDataIcon.className = 'qa-no-data-icon';
            noDataIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
            
            const noDataText = document.createElement('div');
            noDataText.className = 'qa-no-data-text';
            noDataText.textContent = this.__('No data available');
            
            noDataContainer.appendChild(noDataIcon);
            noDataContainer.appendChild(noDataText);
            
            emptyCell.appendChild(noDataContainer);
            
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
            
            if (this.options.pagination) {
                this._updatePagination();
            }
            
            return;
        }
        
        // Get row selection configuration
        const rowSelection = this.options.rowSelection;
        
        displayData.forEach((rowData, rowIndex) => {
            const row = document.createElement('tr');
            
            if (!rowData._rowId) {
                rowData._rowId = rowIndex + 1;
            }
            row.setAttribute('data-row-id', String(rowData._rowId));
            
            
            // 先にすべてのカラムの値を取得して、rowDataに追加しておく
            // これにより、hidden: trueのカラムの値もformatterで利用できるようになる
            this.columns.forEach(column => {
                if (typeof column.key === 'function') {
                    rowData['_computed_' + column.key] = column.key(rowData, rowIndex);
                } else if (!rowData.hasOwnProperty(column.key)) {
                    rowData[column.key] = this._getDataValue(rowData, column.key);
                }
            });
            
            // 表示するカラムのみループ処理
            this.columns.forEach(column => {
                if (column.hidden === true) {
                    return;
                }
                
                const cell = document.createElement('td');
                let value;
                
                if (typeof column.key === 'function') {
                    value = rowData['_computed_' + column.key];
                } else {
                    value = rowData[column.key];
                }
                
                if (column.type === 'check') {
                    cell.className = 'qa-checkbox-cell';
                    cell.style.textAlign = 'center';
                    
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'qa-checkbox';
                    checkbox.dataset.columnKey = column.key;
                    checkbox.dataset.rowId = rowData._rowId;
                    
                    const isChecked = this.columnSelections[column.key] &&
                                     this.columnSelections[column.key].has(rowData._rowId);
                    checkbox.checked = isChecked;
                    
                    const maxSelections = column.typeOptions?.maxSelections;
                    if (maxSelections &&
                        this.columnSelections[column.key] &&
                        this.columnSelections[column.key].size >= maxSelections &&
                        !this.columnSelections[column.key].has(rowData._rowId)) {
                        checkbox.disabled = true;
                    }
                    
                    checkbox.addEventListener('change', () => {
                        this._handleRowSelection(rowData._rowId, checkbox.checked, column.key);
                    });
                    
                    cell.appendChild(checkbox);
                    cell.classList.add('qa-column-check');
                } else if (typeof column.formatter === 'function') {
                    cell.innerHTML = column.formatter(value, rowData, rowIndex);
                } else if (typeof column.render === 'function') {
                    cell.innerHTML = column.render(value, rowData, rowIndex);
                } else if (column.type) {
                    cell.innerHTML = this._formatByType(value, column.type, column.typeOptions);
                    
                    // Add type-based classes
                    if (column.type) {
                        cell.classList.add('qa-column-' + column.type);
                    }
                } else {
                    cell.textContent = value !== undefined && value !== null ? value : '';
                }
            
                
                if (typeof column.textAlign !== 'undefined') {
                    cell.style.textAlign = column.textAlign;
                }
                row.appendChild(cell);
            });
            
            tbody.appendChild(row);
        });
        
        if (this.options.pagination) {
            this._updatePagination();
        }
    }
    
    // ページネーション更新
    _updatePagination() {
        if (!this.options.pagination) return;
        
        const paginationContainer = this.container.querySelector('.qa-pagination');
        if (!paginationContainer) return;
        
        paginationContainer.innerHTML = '';
        
        const totalPages = Math.ceil(this.filteredData.length / this.perPage);
        
        const infoText = document.createElement('div');
        infoText.className = 'qa-pagination-info';
        infoText.textContent = `${this.__('Showing')} ${this.filteredData.length > 0 ? (this.currentPage - 1) * this.perPage + 1 : 0} ${this.__('to')} ${Math.min(this.currentPage * this.perPage, this.filteredData.length)} ${this.__('of')} ${this.filteredData.length} ${this.__('items')}`;
        paginationContainer.appendChild(infoText);
        
        if (totalPages <= 1) {
            return;
        }
        
        const controls = document.createElement('div');
        controls.className = 'qa-pagination-controls';
        
        const prevButton = document.createElement('button');
        prevButton.className = 'qa-pagination-prev';
        prevButton.textContent = this.__('Previous');
        prevButton.disabled = this.currentPage === 1;
        prevButton.addEventListener('click', () => {
            if (this.currentPage > 1) {
                this.goToPage(this.currentPage - 1);
            }
        });
        controls.appendChild(prevButton);
        
        const createPageButton = (pageNum, isCurrent = false) => {
            const pageButton = document.createElement('button');
            pageButton.className = 'qa-pagination-page';
            if (isCurrent) {
                pageButton.classList.add('active');
            }
            pageButton.textContent = pageNum;
            pageButton.addEventListener('click', () => {
                this.goToPage(pageNum);
            });
            return pageButton;
        };
        
        const createEllipsis = () => {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'qa-pagination-ellipsis';
            ellipsis.textContent = '...';
            return ellipsis;
        };
        
        controls.appendChild(createPageButton(1, this.currentPage === 1));
        
        const maxVisiblePages = 5;
        let startPage = Math.max(2, this.currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages - 1, startPage + maxVisiblePages - 1);
        
        if (endPage - startPage + 1 < maxVisiblePages && startPage > 2) {
            startPage = Math.max(2, endPage - maxVisiblePages + 1);
        }
        
        if (startPage > 2) {
            controls.appendChild(createEllipsis());
        }
        
        for (let i = startPage; i <= endPage; i++) {
            controls.appendChild(createPageButton(i, i === this.currentPage));
        }
        
        if (endPage < totalPages - 1) {
            controls.appendChild(createEllipsis());
        }
        
        if (totalPages > 1) {
            controls.appendChild(createPageButton(totalPages, this.currentPage === totalPages));
        }
        
        const nextButton = document.createElement('button');
        nextButton.className = 'qa-pagination-next';
        nextButton.textContent = this.__('Next');
        nextButton.disabled = this.currentPage === totalPages;
        nextButton.addEventListener('click', () => {
            if (this.currentPage < totalPages) {
                this.goToPage(this.currentPage + 1);
            }
        });
        controls.appendChild(nextButton);
        
        paginationContainer.appendChild(controls);
    }
    
    // フィルタリングUI作成
    _createFilteringUI(container) {
        if (!this.options.filtering) return;
        
        const filterContainer = document.createElement('div');
        filterContainer.className = 'qa-filter-container';
        
        const filterTitle = document.createElement('div');
        filterTitle.className = 'qa-filter-title';
        filterTitle.textContent = this.__('Filters');
        filterContainer.appendChild(filterTitle);
        
        const filterForm = document.createElement('div');
        filterForm.className = 'qa-filter-form';
        
        const columnSelect = document.createElement('select');
        columnSelect.className = 'qa-filter-column';
        
        this.columns.forEach(column => {
            if (column.hidden === true || column.filtering === false) {
                return;
            }
            const option = document.createElement('option');
            option.value = column.key;
            option.textContent = column.label || column.key;
            columnSelect.appendChild(option);
        });
        
        filterForm.appendChild(columnSelect);
        
        const typeSelect = document.createElement('select');
        typeSelect.className = 'qa-filter-type';

        const updateTypeOptions = () => {
            typeSelect.innerHTML = '';
            const selectedColumnKey = columnSelect.value;
            const selectedColumn = this.columns.find(col => col.key === selectedColumnKey);
            let options = this.filterOptionsByType.default;
            if (selectedColumn && selectedColumn.type) {
                const colType = selectedColumn.type.toLowerCase();
                if (this.filterOptionsByType[colType]) {
                    options = this.filterOptionsByType[colType];
                }
            }
            options.forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                typeSelect.appendChild(option);
            });
        };

        // Initial update of filter options
        updateTypeOptions();

        // Update filter options when selected column changes
        columnSelect.addEventListener('change', updateTypeOptions);

        filterForm.appendChild(typeSelect);
        
        const valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'qa-filter-value';
        valueInput.placeholder = this.__('Enter filter value');
        valueInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const column = columnSelect.value;
                const type = typeSelect.value;
                const value = valueInput.value;
                
                if (type === QA_FILTER_TYPES.IS_EMPTY || type === QA_FILTER_TYPES.IS_NOT_EMPTY) {
                    this.addFilter(column, type);
                } else if (value) {
                    this.addFilter(column, type, value);
                }
                
                valueInput.value = '';
                this._updateFilterList();
            }
        });
        filterForm.appendChild(valueInput);
        
        const applyButton = document.createElement('button');
        applyButton.className = 'qa-filter-add';
        applyButton.textContent = this.__('Add');
        applyButton.addEventListener('click', () => {
            const column = columnSelect.value;
            const type = typeSelect.value;
            const value = valueInput.value;
            
            if (type === QA_FILTER_TYPES.IS_EMPTY || type === QA_FILTER_TYPES.IS_NOT_EMPTY) {
                this.addFilter(column, type);
            } else if (value) {
                this.addFilter(column, type, value);
            }
            
            valueInput.value = '';
            this._updateFilterList();
        });
        filterForm.appendChild(applyButton);
        
        const resetButton = document.createElement('button');
        resetButton.className = 'qa-filter-clear';
        resetButton.textContent = this.__('Clear');
        resetButton.addEventListener('click', () => {
            this.clearFilters();
            this._updateFilterList();
        });
        filterForm.appendChild(resetButton);
        
        filterContainer.appendChild(filterForm);
        
        const filterList = document.createElement('div');
        filterList.className = 'qa-filter-list';
        filterContainer.appendChild(filterList);
        
        container.appendChild(filterContainer);
        
        this.filterContainer = filterContainer;
        this.filterList = filterList;
        
        this._updateFilterList();
    }
    
    // フィルタ一覧を更新
    _updateFilterList() {
        if (!this.filterList) return;
        
        this.filterList.innerHTML = '';
        
        if (this.filters.length === 0) {
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'qa-filter-empty';
            emptyMessage.textContent = this.__('No filters applied');
            this.filterList.appendChild(emptyMessage);
            return;
        }
        
        const filtersTitle = document.createElement('div');
        filtersTitle.className = 'qa-filters-title';
        filtersTitle.textContent = this.__('Applied filters:');
        this.filterList.appendChild(filtersTitle);
        
        const tagsContainer = document.createElement('div');
        tagsContainer.className = 'qa-filter-tags';
        
        this.filters.forEach((filter, index) => {
            const filterTag = document.createElement('div');
            filterTag.className = 'qa-filter-tag';
            
            const column = this.columns.find(col => col.key === filter.column);
            const columnName = column ? (column.label || column.key) : filter.column;
            
            const columnType = column?.type?.toLowerCase() || 'default';
            const options = this.filterOptionsByType[columnType] || this.filterOptionsByType.default;
            const filterOption = options.find(opt => opt.value === filter.type);
            const typeName = filterOption ? filterOption.label : filter.type;
            
            const filterText = document.createElement('span');
            filterText.className = 'qa-filter-text';
            if (filter.type === QA_FILTER_TYPES.IS_EMPTY || filter.type === QA_FILTER_TYPES.IS_NOT_EMPTY) {
                filterText.textContent = `${columnName} ${typeName}`;
            } else {
                filterText.textContent = `${columnName} ${typeName} "${filter.value}"`;
            }
            filterTag.appendChild(filterText);
            
            const removeButton = document.createElement('button');
            removeButton.className = 'qa-filter-remove';
            removeButton.textContent = '×';
            removeButton.title = this.__('Remove');
            removeButton.addEventListener('click', () => {
                this.removeFilter(index);
                this._updateFilterList();
            });
            
            filterTag.appendChild(removeButton);
            tagsContainer.appendChild(filterTag);
        });
        
        this.filterList.appendChild(tagsContainer);
    }
    
    // フィルタ追加
    addFilter(column, type, value = '') {
        this.filters.push({ column, type, value });
        this._applyFilters();
        this._renderTable();
        
        return this;
    }
    
    // フィルタ削除
    removeFilter(index) {
        if (index >= 0 && index < this.filters.length) {
            this.filters.splice(index, 1);
            this._applyFilters();
            this._renderTable();
        }
        
        return this;
    }
    
    // フィルタクリア
    clearFilters() {
        this.filters = [];
        this.filteredData = [...this.data];
        this.currentPage = 1;
        this._renderTable();
        
        return this;
    }
    
    // フィルタ適用
    _applyFilters() {
        if (this.filters.length === 0) {
            this.filteredData = [...this.data];
            return;
        }
        
        this.filteredData = this.data.filter(item => {
            return this.filters.every(filter => {
                let itemValue = this._getDataValue(item, filter.column);
                
                if (itemValue === undefined || itemValue === null) {
                    itemValue = '';
                }
                
                const stringValue = String(itemValue).toLowerCase();
                const filterValue = String(filter.value).toLowerCase();
				
                switch (filter.type) {
                    case QA_FILTER_TYPES.CONTAINS:
                        return stringValue.includes(filterValue);
                    case QA_FILTER_TYPES.NOT_CONTAINS:
                        return !stringValue.includes(filterValue);
                    case QA_FILTER_TYPES.EQUALS:
                        if (this._getColumnType(filter.column) === 'duration') {
                            const itemSeconds = this._parseDurationToSeconds(itemValue);
                            const filterSeconds = this._parseDurationToSeconds(filter.value);
                            return itemSeconds === filterSeconds;
                        }
                        return stringValue === filterValue;
                    case QA_FILTER_TYPES.NOT_EQUALS:
                        if (this._getColumnType(filter.column) === 'duration') {
                            const itemSeconds = this._parseDurationToSeconds(itemValue);
                            const filterSeconds = this._parseDurationToSeconds(filter.value);
                            return itemSeconds !== filterSeconds;
                        }
                        return stringValue !== filterValue;
                    case QA_FILTER_TYPES.STARTS_WITH:
                        return stringValue.startsWith(filterValue);
                    case QA_FILTER_TYPES.ENDS_WITH:
                        return stringValue.endsWith(filterValue);
                    case QA_FILTER_TYPES.GREATER_THAN:
                        if (this._getColumnType(filter.column) === 'duration') {
                            const itemSeconds = this._parseDurationToSeconds(itemValue);
                            const filterSeconds = this._parseDurationToSeconds(filter.value);
                            return itemSeconds > filterSeconds;
                        }
                        return parseFloat(itemValue) > parseFloat(filter.value);
                    case QA_FILTER_TYPES.LESS_THAN:
                        if (this._getColumnType(filter.column) === 'duration') {
                            const itemSeconds = this._parseDurationToSeconds(itemValue);
                            const filterSeconds = this._parseDurationToSeconds(filter.value);
                            return itemSeconds < filterSeconds;
                        }
                        return parseFloat(itemValue) < parseFloat(filter.value);
                    case QA_FILTER_TYPES.IS_EMPTY:
                        return stringValue === '';
                    case QA_FILTER_TYPES.IS_NOT_EMPTY:
                        return stringValue !== '';
                    default:
                        return true;
                }
            });
        });
        
        this.currentPage = 1;
    }
    
    // ソート適用
        _applySorting() {
            if (!this.options.sortable || this.sortState.length === 0) return;
        
        if (!this.filteredData || this.filteredData.length === 0) return;
        
        const originalData = [...this.filteredData];
        
        try {
            const sortItem = this.sortState[0]; // 最初のソート条件を取得
            if (sortItem) {
                const { column, direction } = sortItem;
                const columnDef = this.columns.find(col => col.key === column);
                const columnType = columnDef ? columnDef.type : null;
                
                if (columnType === 'check') {
                    const checkedRows = [];
                    const uncheckedRows = [];
                    
                    for (const row of this.filteredData) {
                        if (this.columnSelections[sortItem.column] && this.columnSelections[sortItem.column].has(row._rowId)) {
                            checkedRows.push(row);
                        } else {
                            uncheckedRows.push(row);
                        }
                    }
                    
                    checkedRows.sort((a, b) => a._rowId - b._rowId);
                    uncheckedRows.sort((a, b) => a._rowId - b._rowId);
                    
                    if (direction === 'asc') {
                        this.filteredData = [...uncheckedRows, ...checkedRows];
                    } else {
                        this.filteredData = [...checkedRows, ...uncheckedRows];
                    }
                    
                    return; // チェックボックスカラムのソートが完了したので終了
                }
            }
            
            this.filteredData.sort((a, b) => {
                if (!a || !b) return 0;
                
                for (const sortItem of this.sortState) {
                    const { column, direction } = sortItem;
                    
                    // カラム定義を取得して型を確認
                    const columnDef = this.columns.find(col => col.key === column);
                    const columnType = columnDef ? columnDef.type : null;
                    
                    let valueA = this._getDataValue(a, column);
                    let valueB = this._getDataValue(b, column);
                    
                    // null/undefined値の処理
                    if (valueA === null || valueA === undefined) valueA = '';
                    if (valueB === null || valueB === undefined) valueB = '';
                    
                    // duration型の場合は数値に変換して比較
                    if (columnType === 'duration') {
                        // 既に数値の場合はそのまま、文字列の場合は変換
                        const numA = typeof valueA === 'number' ? valueA : this._parseDurationToSeconds(valueA);
                        const numB = typeof valueB === 'number' ? valueB : this._parseDurationToSeconds(valueB);
                        
                        if (numA !== numB) {
                            return direction === 'asc' ? numA - numB : numB - numA;
                        }
                    }
                    // 数値の場合は数値比較
                    else if (typeof valueA === 'number' && typeof valueB === 'number') {
                        if (valueA !== valueB) {
                            return direction === 'asc' ? valueA - valueB : valueB - valueA;
                        }
                    } 
                    // 日付の場合は日付比較
                    else if (valueA instanceof Date && valueB instanceof Date) {
                        const timeA = valueA.getTime();
                        const timeB = valueB.getTime();
                        if (timeA !== timeB) {
                            return direction === 'asc' ? timeA - timeB : timeB - timeA;
                        }
                    }
                    // 文字列の場合は文字列比較
                    else {
                        const strA = String(valueA).toLowerCase();
                        const strB = String(valueB).toLowerCase();
                        if (strA !== strB) {
                            return direction === 'asc' ? strA.localeCompare(strB) : strB.localeCompare(strA);
                        }
                    }
                }
                return 0;
            });
        } catch (error) {
            console.error(this.__('Error occurred during sorting:'), error);
            this.filteredData = originalData;
        }
    }
    
    // ソート処理
    _handleSort(column, isMultiSort = false, initialDirection = 'desc') {
        // 既存のソート状態を確認
        const existingSortIndex = this.sortState.findIndex(item => item.column === column);
        
        // Ctrlが押されていない場合は常に既存のソートをクリア
        if (!isMultiSort) {
            // 同じカラムの場合は方向だけ変更
            if (existingSortIndex !== -1) {
                const currentDirection = this.sortState[existingSortIndex].direction;
                this.sortState = [{
                    column: column,
                    direction: currentDirection === 'asc' ? 'desc' : 'asc'
                }];
            } else {
                // 新しいカラムの場合は既存のソートをクリアして新しいソートを設定
                this.sortState = [{
                    column: column,
                    direction: initialDirection
                }];
            }
            
            // マルチソートの痕跡を完全に消すために、すべてのソートアイコンをリセット
            const sortHeaders = this.container.querySelectorAll('.qa-sortable');
            sortHeaders.forEach(header => {
                const sortIcon = header.querySelector('.qa-sort-icon');
                if (sortIcon) {
                    sortIcon.className = 'qa-sort-icon';
                    sortIcon.removeAttribute('data-sort-index');
                }
            });
        } else {
            // マルチソート（Ctrlキー押下時）
            if (existingSortIndex !== -1) {
                // 既存のソート方向を変更 (昇順 ⇔ 降順)
                const currentDirection = this.sortState[existingSortIndex].direction;
                this.sortState[existingSortIndex].direction = currentDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // 新しいソート条件を追加
                this.sortState.push({
                    column: column,
                    direction: initialDirection
                });
            }
        }
        
        // ソートアイコンを更新
        this._updateSortIcons();
        
        // テーブルを再描画
        this._renderTable();
    }
    
    // ソートアイコン更新
    _updateSortIcons() {
        // すべてのソートアイコンをリセット
        const sortHeaders = this.container.querySelectorAll('.qa-sortable');
        sortHeaders.forEach(header => {
            // ヘッダーからソート関連のクラスを削除
            header.classList.remove('qa-sorted', 'qa-sort-asc', 'qa-sort-desc');
            
            // ソートヘッダーの内容を更新
            const headerContent = header.querySelector('.qa-sort-header');
            if (!headerContent) {
                // ソートヘッダーがない場合は作成
                const originalContent = header.innerHTML;
                const sortHeader = document.createElement('div');
                sortHeader.className = 'qa-sort-header';
                
                const textSpan = document.createElement('span');
                textSpan.className = 'qa-sort-text';
                textSpan.innerHTML = originalContent;
                
                const iconSpan = document.createElement('span');
                iconSpan.className = 'qa-sort-icon';
                
                sortHeader.appendChild(textSpan);
                sortHeader.appendChild(iconSpan);
                
                header.innerHTML = '';
                header.appendChild(sortHeader);
            } else {
                // 既存のアイコンをリセット
                const icon = header.querySelector('.qa-sort-icon');
                if (icon) {
                    icon.textContent = '';
                    icon.classList.remove('qa-sort-asc', 'qa-sort-desc');
                }
            }
        });
        
        // アクティブなソートのアイコンを設定
        this.sortState.forEach((sortItem, index) => {
            const column = this.columns.find(col => col.key === sortItem.column);
            if (!column) return;
            
            const columnIndex = this.columns.indexOf(column);
            const headers = this.container.querySelectorAll('.qa-sortable');
            
            // 非表示カラムを考慮したインデックス調整
            let visibleIndex = 0;
            let targetHeader = null;
            
            for (let i = 0; i <= columnIndex; i++) {
                if (this.columns[i].hidden !== true) {
                    if (i === columnIndex) {
                        targetHeader = headers[visibleIndex];
                    }
                    visibleIndex++;
                }
            }
            
            if (targetHeader) {
                // ヘッダーにソート状態のクラスを追加
                targetHeader.classList.add('qa-sorted');
                targetHeader.classList.add(sortItem.direction === 'asc' ? 'qa-sort-asc' : 'qa-sort-desc');
                
                // アイコンを更新
                const icon = targetHeader.querySelector('.qa-sort-icon');
                if (icon) {
                    // ソート方向に応じたアイコンを設定
                    icon.textContent = sortItem.direction === 'asc' ? '▲' : '▼';
                    icon.classList.add(sortItem.direction === 'asc' ? 'qa-sort-asc' : 'qa-sort-desc');
                    
                    // マルチソートの場合はインデックスを表示
                    if (this.sortState.length > 1) {
                        icon.setAttribute('data-sort-index', (index + 1).toString());
                    } else {
                        icon.removeAttribute('data-sort-index');
                    }
                }
            }
        });
    }
    
    // その他の必要なメソッド
    
    goToPage(pageNumber) {
        const totalPages = Math.ceil(this.filteredData.length / this.perPage);
        this.currentPage = Math.max(1, Math.min(pageNumber, totalPages));
        this._renderTable();
        
        return this;
    }
    
    _handleRowSelection(rowId, isSelected, columnKey) {
        if (!columnKey) {
            return false;
        }
        
        if (!this.columnSelections) {
            this.columnSelections = {};
        }
        
        if (!this.columnSelections[columnKey]) {
            this.columnSelections[columnKey] = new Set();
        }
        
        const column = this.columns.find(col => col.key === columnKey);
        const maxSelections = column && column.typeOptions?.maxSelections;
        
        const numericRowId = typeof rowId === 'string' ? parseInt(rowId, 10) : rowId;
        
        if (isSelected) {
            const alreadySelected = this.columnSelections[columnKey].has(numericRowId);
            
            if (maxSelections && 
                this.columnSelections[columnKey].size >= maxSelections && 
                !alreadySelected) {
                console.log(this.__('Maximum selection limit reached. Cannot select more items.'));
                return false; // 選択を防止
            }
            
            this.columnSelections[columnKey].add(numericRowId);
            
            const row = this.container.querySelector(`tr[data-row-id="${numericRowId}"]`);
            if (row) {
                row.classList.add('selected');
            }
        } else {
            this.columnSelections[columnKey].delete(numericRowId);
            
            const row = this.container.querySelector(`tr[data-row-id="${numericRowId}"]`);
            if (row) {
                row.classList.remove('selected');
            }
        }
        
        if (maxSelections) {
            this._updateCheckboxDisabledState(columnKey);
        }
        
        console.log(this.__('Row selection updated:'), columnKey, numericRowId, isSelected, 
                   this.__('Selection count:'), this.columnSelections[columnKey].size, 
                   this.__('Max selections:'), maxSelections);
        return true;
    }
    
    // Add method to update checkbox disabled state
    _updateCheckboxDisabledState(columnKey) {
        if (!columnKey) {
            return;
        }
        
        const column = this.columns.find(col => col.key === columnKey);
		const maxSelections = column && column.typeOptions?.maxSelections;
        if (!column || !maxSelections) {
            return;
        }
        
        const checkboxes = this.container.querySelectorAll(`.qa-checkbox[data-column-key="${columnKey}"]`);
        
        const selectedCount = this.columnSelections[columnKey] ? this.columnSelections[columnKey].size : 0;
        const atMaxSelections = selectedCount >= maxSelections;
        
        checkboxes.forEach(checkbox => {
            checkbox.disabled = atMaxSelections && !checkbox.checked;
        });
        
        console.log(`Column ${columnKey}: Selected ${selectedCount}/${maxSelections}, At max: ${atMaxSelections}`);
    }
    
    /**
     * 行が任意の選択列でチェックされているかを確認
     * @param {number|string} rowId - 行ID
     * @returns {boolean} チェックされている場合はtrue
     */
    _isAnyColumnChecked(rowId) {
        if (!this.columnSelections) {
            return false;
        }
        
        const numericRowId = typeof rowId === 'string' ? parseInt(rowId, 10) : rowId;
        
        for (const columnKey in this.columnSelections) {
            if (this.columnSelections[columnKey] && this.columnSelections[columnKey].has(numericRowId)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * チェックボックスの値を操作する関数
     * @param {string} columnKey - チェックボックス列のキー
     * @param {boolean} isChecked - チェックボックスの状態（true: チェック, false: 未チェック）
     * @param {string|number|null} rowId - 行ID（文字列）または行インデックス（数値）、nullの場合は全ての行に適用
     * @returns {boolean} 操作が成功したかどうか
     */
    setCheckboxValue(columnKey, isChecked, rowId = null) {
        if (!columnKey) {
            throw new Error(this.__('Please specify a column key'));
        }
        
        const column = this.columns.find(col => col.key === columnKey && col.type === 'check');
        if (!column) {
            console.error(this.__('Checkbox column not found:'), `${columnKey}`);
            return false;
        }
        
        let columnIndex = -1;
        let visibleColumnIndex = 0;
        
        for (let i = 0; i < this.columns.length; i++) {
            if (this.columns[i].hidden) continue;
            if (this.columns[i].key === columnKey) {
                columnIndex = i;
                break;
            }
            visibleColumnIndex++;
        }
        
        if (columnIndex === -1) {
            console.error(`Column with key "${columnKey}" not found or is hidden`);
            return false;
        }
        
        console.log(`Found column "${columnKey}" at visible index ${visibleColumnIndex}`);
        
        if (rowId !== null && (typeof rowId === 'number' || (typeof rowId === 'string' && !isNaN(parseInt(rowId))))) {
            console.log(`Converting numeric index ${rowId} to row ID`);
            const adjustedIndex = parseInt(rowId) - 1;
            if (adjustedIndex >= 0 && adjustedIndex < this.filteredData.length) {
                const rowData = this.filteredData[adjustedIndex];
                rowId = rowData._rowId;
                console.log(`Converted to row ID: ${rowId}`);
            } else {
                console.error(`Index out of bounds: ${adjustedIndex}`);
            }
        }
        
        if (rowId === null) {
            console.log(`Setting all checkboxes for column ${columnKey} to ${isChecked}`);
            
            const rows = this.container.querySelectorAll('tbody tr');
            let success = false;
            
            const column = this.columns.find(col => col.key === columnKey);
            const maxSelections = column && column.typeOptions?.maxSelections;
            
            let currentSelectionCount = 0;
            if (this.columnSelections && this.columnSelections[columnKey]) {
                if (!isChecked) {
                    currentSelectionCount = this.columnSelections[columnKey].size;
                } else {
                    this.columnSelections[columnKey].clear();
                }
            }
            
            let remainingSelections = maxSelections || Infinity;
            
            if (isChecked && maxSelections && remainingSelections <= 0) {
                console.log(this.__('Maximum selection limit reached. Cannot select more items.'));
                return false;
            }
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const rowDataId = row.dataset.rowId || this.filteredData[i]?._rowId;
                if (!rowDataId) continue;
                
                if (visibleColumnIndex < row.cells.length) {
                    const cell = row.cells[visibleColumnIndex];
                    const checkbox = cell.querySelector('input[type="checkbox"]');
                    
                    if (checkbox) {
                        if (isChecked) {
                            if (currentSelectionCount < maxSelections) {
                                checkbox.checked = true;
                                if (this._handleRowSelection(rowDataId, true, columnKey)) {
                                    currentSelectionCount++;
                                    success = true;
                                }
                            } else {
                                checkbox.checked = false;
                                checkbox.disabled = true;
                            }
                        } else {
                            checkbox.checked = false;
                            if (this._handleRowSelection(rowDataId, false, columnKey)) {
                                success = true;
                            }
                        }
                    }
                }
            }
            
            this._updateCheckboxDisabledState(columnKey);
            
            return success;
        } else {
            console.log(`Setting checkbox for row ID ${rowId} in column ${columnKey} to ${isChecked}`);
            
            const column = this.columns.find(col => col.key === columnKey);
            const maxSelections = column && column.typeOptions?.maxSelections;
            
            if (isChecked && maxSelections) {
                let currentSelectionCount = 0;
                if (this.columnSelections && this.columnSelections[columnKey]) {
                    currentSelectionCount = this.columnSelections[columnKey].size;
                }
                
                const isAlreadySelected = this.columnSelections[columnKey] && 
                                         this.columnSelections[columnKey].has(parseInt(rowId, 10));
                
                if (currentSelectionCount >= maxSelections && !isAlreadySelected) {
                    console.log(this.__('Maximum selection limit reached. Cannot select more items.'));
                    return false;
                }
            }
            
            const checkbox = this.container.querySelector(`.qa-checkbox[data-column-key="${columnKey}"][data-row-id="${rowId}"]`);
            if (checkbox) {
                console.log(`Found checkbox with data-row-id=${rowId} for column ${columnKey}`);
                checkbox.checked = isChecked;
                return this._handleRowSelection(rowId, isChecked, columnKey);
            }
            
            let rowIndex = -1;
            
            if (typeof rowId === 'number') {
                rowIndex = rowId - 1; // 1ベースから0ベースに変換
            } else {
                const rows = this.container.querySelectorAll('tbody tr');
                for (let i = 0; i < rows.length; i++) {
                    if (rows[i].dataset.rowId === rowId) {
                        rowIndex = i;
                        break;
                    }
                }
                
                if (rowIndex === -1) {
                    for (let i = 0; i < this.filteredData.length; i++) {
                        if (this.filteredData[i]._rowId === rowId) {
                            if (this.options.pagination) {
                                const startIndex = (this.currentPage - 1) * this.perPage;
                                const endIndex = startIndex + this.perPage;
                                if (i >= startIndex && i < endIndex) {
                                    rowIndex = i - startIndex;
                                }
                            } else {
                                rowIndex = i;
                            }
                            break;
                        }
                    }
                }
            }
            
            if (rowIndex === -1 && typeof rowId === 'string' && !isNaN(parseInt(rowId))) {
                rowIndex = parseInt(rowId) - 1; // 1ベースから0ベースに変換
            }
            
            if (rowIndex === -1) {
                console.error(`Row with ID ${rowId} not found`);
                return false;
            }
            
            console.log(`Found row at index ${rowIndex}`);
            
            const rows = this.container.querySelectorAll('tbody tr');
            if (rowIndex >= 0 && rowIndex < rows.length) {
                const row = rows[rowIndex];
                const actualRowId = row.dataset.rowId || rowId;
                
                if (visibleColumnIndex < row.cells.length) {
                    const cell = row.cells[visibleColumnIndex];
                    const checkbox = cell.querySelector('input[type="checkbox"]');
                    
                    if (checkbox) {
                        console.log(`Found checkbox in row ${rowIndex}, column ${visibleColumnIndex}`);
                        checkbox.checked = isChecked;
                        return this._handleRowSelection(actualRowId, isChecked, columnKey);
                    } else {
                        console.error(`No checkbox found in cell at row ${rowIndex}, column ${visibleColumnIndex}`);
                    }
                } else {
                    console.error(`Column index ${visibleColumnIndex} out of bounds (max: ${row.cells.length - 1})`);
                }
            } else {
                console.error(`Row index ${rowIndex} out of bounds (max: ${rows.length - 1})`);
            }
            
            return false;
        }
    }
    
    /**
     * チェックされた行のデータを取得する
     * @param {string} [columnKey] - チェックボックス列のキー（省略可能）
     * @returns {Array} チェックされた行のデータオブジェクトの配列
     */
    getCheckedData(columnKey) {
        const checkedData = [];
        
        if (!columnKey) {
            const allCheckedRowIds = new Set();
            
            Object.keys(this.columnSelections || {}).forEach(key => {
                if (this.columnSelections[key]) {
                    if (this.columnSelections[key] instanceof Set) {
                        this.columnSelections[key].forEach(rowId => {
                            const numericRowId = typeof rowId === 'string' ? parseInt(rowId, 10) : rowId;
                            allCheckedRowIds.add(numericRowId);
                        });
                    } 
                    else if (Array.isArray(this.columnSelections[key])) {
                        this.columnSelections[key].forEach(rowId => {
                            const numericRowId = typeof rowId === 'string' ? parseInt(rowId, 10) : rowId;
                            allCheckedRowIds.add(numericRowId);
                        });
                    }
                }
            });
            
            // チェックされた行IDを反復処理
            allCheckedRowIds.forEach(rowId => {
                // 対応するデータオブジェクトを検索
                const rowData = this.data.find(item => {
                    const itemRowId = typeof item._rowId === 'string' ? parseInt(item._rowId, 10) : item._rowId;
                    return itemRowId === rowId;
                });
                
                if (rowData) {
                    // 内部プロパティを除外した新しいオブジェクトを作成
                    const cleanData = {};
                    Object.keys(rowData).forEach(key => {
                        if (key !== '_rowId') {
                            cleanData[key] = rowData[key];
                        }
                    });
                    cleanData.id = rowId;
                    checkedData.push(cleanData);
                }
            });
            
            console.log(this.__('Checked data (all columns):'), checkedData);
            return checkedData;
        }
        
        if (!this.columnSelections || !this.columnSelections[columnKey]) {
            console.log(this.__('No checked data for column key:'), ' ' + columnKey + '');
            return checkedData;
        }
        
        // チェックされた行IDを反復処理
        if (this.columnSelections[columnKey] instanceof Set) {
            this.columnSelections[columnKey].forEach(rowId => {
                const numericRowId = typeof rowId === 'string' ? parseInt(rowId, 10) : rowId;
                
                // 対応するデータオブジェクトを検索
                const rowData = this.data.find(item => {
                    const itemRowId = typeof item._rowId === 'string' ? parseInt(item._rowId, 10) : item._rowId;
                    return itemRowId === numericRowId;
                });
                
                if (rowData) {
                    // 内部プロパティを除外した新しいオブジェクトを作成
                    const cleanData = {};
                    Object.keys(rowData).forEach(key => {
                        if (key !== '_rowId') {
                            cleanData[key] = rowData[key];
                        }
                    });
                    cleanData.id = numericRowId;
                    checkedData.push(cleanData);
                }
            });
        } 
        else if (Array.isArray(this.columnSelections[columnKey])) {
            this.columnSelections[columnKey].forEach(rowId => {
                const numericRowId = typeof rowId === 'string' ? parseInt(rowId, 10) : rowId;
                
                // 対応するデータオブジェクトを検索
                const rowData = this.data.find(item => {
                    const itemRowId = typeof item._rowId === 'string' ? parseInt(item._rowId, 10) : item._rowId;
                    return itemRowId === numericRowId;
                });
                
                if (rowData) {
                    // 内部プロパティを除外した新しいオブジェクトを作成
                    const cleanData = {};
                    Object.keys(rowData).forEach(key => {
                        if (key !== '_rowId') {
                            cleanData[key] = rowData[key];
                        }
                    });
                    cleanData.id = numericRowId;
                    checkedData.push(cleanData);
                }
            });
        }
        
        console.log(this.__('Checked data (column key:'), ' ' + columnKey + '):', checkedData);
        return checkedData;
    }
    
    // エクスポート機能
    /**
     * 値をカラムタイプに基づいてフォーマットする
     * @param {*} value - フォーマットする値
     * @param {string} type - カラムタイプ (QA_COLUMN_TYPES から)
     * @param {Object} options - フォーマットオプション
     * @returns {string} - フォーマットされた値
     */
    _formatByType(value, type, options) {
        if (value === undefined || value === null || value === '') {
            return '';
        }
        
        options = options || {};
        
        switch (type) {
            case 'number':
            case 'integer':
                return Number(value).toLocaleString();
                
            case 'float':
                const floatPrecision = options.precision !== undefined ? options.precision : 2;
                return Number(value).toLocaleString(undefined, { 
                    minimumFractionDigits: floatPrecision, 
                    maximumFractionDigits: floatPrecision 
                });
                
            case 'currency':
                const currency = options.currency || '¥';
                return currency + Number(value).toLocaleString();
                
            case 'percentage':
                const percentPrecision = options.precision !== undefined ? options.precision : 2;
                return Number(value).toLocaleString(undefined, { 
                    minimumFractionDigits: percentPrecision, 
                    maximumFractionDigits: percentPrecision 
                }) + '%';
                
            case 'date':
                const date = new Date(value);
                const year = date.getFullYear();
                const month = (date.getMonth() + 1).toString().padStart(2, '0');
                const day = date.getDate().toString().padStart(2, '0');
                return `${year}/${month}/${day}`;
                
            case 'datetime':
                const datetime = new Date(value);
                return datetime.toLocaleString();
                
            case 'boolean':
                return value ? (options.trueLabel || this.__('Yes')) : (options.falseLabel || this.__('No'));
                
            case 'duration':
                // 秒数を時間表示（HH:MM:SS）に変換
                if (isNaN(value)) return String(value);
                
                const seconds = Math.floor(Number(value));
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                const secs = seconds % 60;
                
                // 2桁の数字にフォーマット
                const format = (num) => num.toString().padStart(2, '0');
                
                return `${format(hours)}:${format(minutes)}:${format(secs)}`;
                
            case 'filesize':
                // バイト数を適切な単位に変換
                if (isNaN(value)) return String(value);
                
                const bytes = Number(value);
                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                
                if (bytes === 0) return '0 B';
                
                const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
                const size = (bytes / Math.pow(1024, exponent)).toFixed(2);
                
                return `${size} ${units[exponent]}`;
                
            case 'link':
                // URLをクリック可能なリンクに変換
                const url = String(value);
                const text = options.text || url;
                const target = options.newTab !== false ? 'target="_blank" rel="noopener noreferrer"' : '';
                
                return `<a href="${url}" ${target}>${text}</a>`;
                
            case 'timestamp':
                // UNIXタイムスタンプを日時表示に変換
                if (isNaN(value)) return String(value);
                
                const timestamp = new Date(Number(value) * 1000); // ミリ秒に変換
                return timestamp.toLocaleString();
                
            case 'html':
                // HTMLをサニタイズして表示
                if (typeof DOMPurify !== 'undefined') {
                    return DOMPurify.sanitize(String(value));
                } else {
                    // DOMPurifyが利用できない場合は、基本的なXSS対策を実施
                    const tempDiv = document.createElement('div');
                    tempDiv.textContent = String(value);
                    return tempDiv.innerHTML;
                }
                
            default:
                return String(value);
        }
    }
    
    _createExportButtons(container) {
        if (!this.options.exportable) return;
        
        const exportContainer = document.createElement('div');
        exportContainer.className = 'qa-export-container';
        
        const csvButton = document.createElement('button');
        csvButton.className = 'qa-export-button qa-export-csv';
        csvButton.textContent = this.__('CSV Export');
        csvButton.addEventListener('click', () => {
            this.exportToCSV();
        });
        
        const jsonButton = document.createElement('button');
        jsonButton.className = 'qa-export-button qa-export-json';
        jsonButton.textContent = this.__('JSON Export');
        jsonButton.addEventListener('click', () => {
            this.exportToJSON();
        });
        
        exportContainer.appendChild(csvButton);
        exportContainer.appendChild(jsonButton);
        
        container.appendChild(exportContainer);
    }
    
    exportToCSV(filename = 'table-export.csv') {
		const exportableColumns = this.columns.filter(column => 
            column.hidden !== true && column.exportable !== false
        );
        
        const headers = exportableColumns.map(column => column.label || column.key);
        
        const rows = this.filteredData.map((item, rowIndex) => {
            return exportableColumns.map(column => {
                let value;
                if (typeof column.formatter === 'function') {
                    value = column.formatter(this._getDataValue(item, column.key), item, rowIndex);
                } else if (typeof column.render === 'function') {
                    value = column.render(this._getDataValue(item, column.key), item, rowIndex);
                } else if (column.type) {
                    value = this._formatByType(this._getDataValue(item, column.key), column.type, column.typeOptions);
                } else {
                    value = this._getDataValue(item, column.key);
                }

				if (value === undefined || value === null) {
                    return '';
                }
                
                // CSVエスケープ処理
                value = String(value);
                if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                    value = '"' + value.replace(/"/g, '""') + '"';
                }
                
                return value;
            }).join(',');
        });
        
        const csvContent = [headers.join(','), ...rows].join('\n');
        this._downloadFile(csvContent, filename, 'text/csv');
        
        return this;
    }
    
    exportToJSON(filename = 'table-export.json') {
		const exportableColumns = this.columns.filter(column => 
            column.hidden !== true && column.exportable !== false
        );
        
        const exportData = this.filteredData.map((item, rowIndex) => {
            const exportItem = {};
            
            exportableColumns.forEach(column => {
                let value;
                if (typeof column.formatter === 'function') {
                    value = column.formatter(this._getDataValue(item, column.key), item, rowIndex);
                } else if (typeof column.render === 'function') {
                    value = column.render(this._getDataValue(item, column.key), item, rowIndex);
                } else if (column.type) {
                    value = this._formatByType(this._getDataValue(item, column.key), column.type, column.typeOptions);
                } else {
                    value = this._getDataValue(item, column.key);
                }
                
                exportItem[column.label] = value;
            });
            
            return exportItem;
        });
        
        const jsonContent = JSON.stringify(exportData, null, 2);
        this._downloadFile(jsonContent, filename, 'application/json');
        
        return this;
    }
    
    _downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        
        document.body.appendChild(link);
        link.click();
        
        setTimeout(() => {
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }, 100);
    }
    
    /**
     * テーブルデータを更新する
     * @param {Array} newData - 新しいデータ配列（オブジェクトまたは2次元配列）
     */
    updateData(newData) {
        if (!Array.isArray(newData)) {
            console.error(this.__('updateData: Data must be an array'));
            return;
        }
        
        // ローディングスピナーを表示
        this.showLoading();
        
        // 2次元配列かどうかを判定
        const is2DArray = newData.length > 0 && Array.isArray(newData[0]);
        
        // データを更新（2次元配列の場合はコピーせずに参照を保持してメモリ使用量を削減）
        this.data = newData;
        this.filteredData = is2DArray ? newData : [...newData];
        
        this.filteredData.forEach((row, index) => {
            if (is2DArray) {
                row._rowId = index + 1;
            } else {
                row._rowId = row.internalId || (index + 1);
            }
        });
        // Clear and initialize check column selections based on default boolean values in the data.
        this.columns.forEach(col => {
            if (col.type === 'check') {
                this.columnSelections[col.key] = new Set();
                let trueCount = 0;
                this.data.forEach(row => {
                    const checkValue = this._getDataValue(row, col.key);
                    if (checkValue === true) {
                    if (!col.typeOptions?.maxSelections || trueCount < col.typeOptions.maxSelections) {
                            this.columnSelections[col.key].add(row._rowId);
                            trueCount++;
                        } else {
                            // Exceeds maxSelections: force false in the row.
                            if (Array.isArray(row)) {
                                const colIndex = this.columns.findIndex(c => c.key === col.key);
                                if (colIndex !== -1 && colIndex < row.length) {
                                    row[colIndex] = false;
                                }
                            } else {
                                row[col.key] = false;
                            }
                        }
                    }
                });
            }
        });
        
        // 選択状態をリセット
        this.selectedRows.clear();
        
        // フィルタを適用（フィルタがある場合）
        if (this.filters.length > 0) {
            this._applyFilters();
        }
        
        // ソートを適用（ソート状態がある場合）
        if (this.sortState.length > 0) {
            this._applySorting();
        }
        
        // 1ページ目に戻す
        this.currentPage = 1;
        
        // ローディング非表示後にテーブルを更新するための処理
        const currentTime = new Date().getTime();
        const elapsedTime = currentTime - (this.loadingStartTime || 0);
        
        // 0.5秒以上経過している場合は即時更新
        if (elapsedTime >= 500) {
            this._renderTable();
            this._hideLoading();
        } else {
            // 0.5秒経過するまで待機してから更新
            setTimeout(() => {
                this._renderTable();
                this._hideLoading();
            }, 500 - elapsedTime);
        }
    }
    
    /**
     * データ要素へのアクセスを提供する
     * @param {Array|Object} item - データ行（配列またはオブジェクト）
     * @param {string|number} key - アクセスするキーまたはインデックス
     * @returns {*} - データ値
     */
    _getDataValue(item, key) {
        // 2次元配列のデータ処理
        if (Array.isArray(item)) {
            // 'active' キーの場合は明示的にインデックス9を返す
            if (key === 'active') {
                return item[9];
            }
            const columnIndex = typeof key === 'number' ? key : this.columns.findIndex(col => col.key === key);
            return columnIndex >= 0 && columnIndex < item.length ? item[columnIndex] : undefined;
        }
        // オブジェクト形式のデータ処理
        return item[key];
    }

    /**
     * カラムタイプを取得する
     * @param {string} columnKey - カラムキー
     * @returns {string|null} - カラムタイプ
     */
    _getColumnType(columnKey) {
        const column = this.columns.find(col => col.key === columnKey);
        return column ? column.type : null;
    }

    /**
     * 時間表示を秒数に変換する
     * 対応フォーマット:
     * - 数値（秒数として扱う）
     * - 数字のみの文字列（秒数として扱う）
     * - HH:MM:SS形式（例: 01:30:45）
     * - MM:SS形式（例: 30:45）
     * 
     * @param {string|number} durationStr - 変換する時間文字列または秒数
     * @returns {number} - 秒数
     * @private
     */
    _parseDurationToSeconds(durationStr) {
        // 数値の場合はそのまま返す
        if (typeof durationStr === 'number') return durationStr;
        
        // 空または文字列でない場合は0を返す
        if (!durationStr || typeof durationStr !== 'string') return 0;
        
        // 数字のみの場合はそのまま返す（秒数と仮定）
        if (/^\d+$/.test(durationStr)) {
            return parseInt(durationStr, 10);
        }
        
        // HH:MM:SS形式をパース
        const colonParts = durationStr.split(':');
        if (colonParts.length === 3) {
            const hours = parseInt(colonParts[0], 10) || 0;
            const minutes = parseInt(colonParts[1], 10) || 0;
            const seconds = parseInt(colonParts[2], 10) || 0;
            return hours * 3600 + minutes * 60 + seconds;
        }
        
    // MM:SS形式をパース
    if (colonParts.length === 2) {
        const minutes = parseInt(colonParts[0], 10) || 0;
        const seconds = parseInt(colonParts[1], 10) || 0;
        return minutes * 60 + seconds;
    }
    
    return 0;
    }

    
    /**
     * ローディングスピナーを表示する
     */
    showLoading() {
        // ローディング開始時間を記録
        this.loadingStartTime = new Date().getTime();
        
        if (!this.loadingOverlay) {
            this.loadingOverlay = document.createElement('div');
            this.loadingOverlay.className = 'qa-table-loading-overlay';
            
            const loader = document.createElement('div');
            loader.className = 'qa-table-loading';
            loader.innerHTML = '<div class="qa-table-loading-icon-wrap"><div><div class="qa-table-loading-icon-bounceball"></div><div class="qa-table-loading-icon-text">' + this.__('Now Loading') + '</div></div></div>';
            this.loadingOverlay.appendChild(loader);
            
            const mainContainer = this.container.querySelector('.qa-table-main-container');
            if (mainContainer) {
                // テーブル要素を取得
                const table = this.container.querySelector('.qa-table');
                
                // maxHeightの設定がある場合、テーブルの高さを設定
                if (table && this.options.maxHeight) {
                    const height = typeof this.options.maxHeight === 'number' 
                        ? `${this.options.maxHeight}px` 
                        : this.options.maxHeight;
                    table.style.height = height;
                }
                
                mainContainer.appendChild(this.loadingOverlay);
            }
        } else {
            this.loadingOverlay.classList.remove('qa-hidden');
        }
    }
    
    /**
     * ローディングアイコンを非表示にする（内部メソッド）
     * @private
     */
    _hideLoading() {
        if (this.loadingOverlay) {
            const currentTime = new Date().getTime();
            const elapsedTime = currentTime - (this.loadingStartTime || 0);
            
            // ローディングアイコンを最低0.5秒間表示するための処理
            const hideLoadingAndResetHeight = () => {
                // ローディングオーバーレイを非表示
                this.loadingOverlay.classList.add('qa-hidden');
                
                // テーブルの高さ設定を削除
                const table = this.container.querySelector('.qa-table');
                if (table) {
                    table.style.height = '';
                }
            };
            
            if (elapsedTime >= 500) {
                // 0.5秒以上経過している場合は即時非表示
                hideLoadingAndResetHeight();
            } else {
                // 0.5秒経過するまで待機してから非表示
                setTimeout(() => {
                    hideLoadingAndResetHeight();
                }, 500 - elapsedTime);
            }
        }
    }
}

if (typeof window !== 'undefined') {
    window.qaTable = qaTable;
}
