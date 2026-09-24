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
 *
 * 依存（このファイル単体は無依存で動作）:
 * - 列 type:"html" を「HTML として描画」するには qahm-html-sanitizer.js（= qahm.sanitizeHtml）と
 *   その依存 DOMPurify を一緒に読み込むこと。両方揃うと allowlist でサニタイズして描画する。
 * - どちらか欠けても壊れない: type:"html" は自動でエスケープした素テキスト表示に安全劣化し（+警告1回）、
 *   他の全 type は無依存で完全動作する。＝サニタイズ経路が無い環境でも XSS は発生しない。
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

// type:'html' 列のサニタイズは共有ヘルパ qahm.sanitizeHtml() に委譲する（#1426）。
// config・許可 class・専用インスタンス・フックは qahm-html-sanitizer.js に集約
// （table 列 html と会話 html が「同じ許可基準＝2入口・1実体」を通るため）。
// qa-table は単体で外部へ持ち出すことがあるため、ヘルパ未ロードでも case 'html' 内で
// 自前 escape に安全劣化する（下記 _formatByType のガード参照）＝可搬性を保つ。
// ヘルパ不在フォールバックの warn を1回だけに抑えるフラグ。
let qaTablePurifyWarned = false;

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
            stickyHeader: false,
            columnToggle: false,
            totalRow: false, // Issue #1175: 合計行（既定オフ・呼び出し側で totalRow:true でオプトイン）
            onRowClick: null // Issue #1386: 行クリック選択（既定 null=無効・関数を渡すと実データ行がクリック可能になる）
        };
        
        this.container = typeof selector === 'string' 
            ? document.querySelector(selector) 
            : selector;
        
        if (!this.container) {
            throw new Error(this.__('Container not found: ') + selector);
        }
        
        // 2次元配列かどうかを判定（インスタンスプロパティとして保持）
        this.is2DArray = Array.isArray(data) && data.length > 0 && Array.isArray(data[0]);

        // データを保存（2次元配列の場合はコピーせずに参照を保持してメモリ使用量を削減）
        this.data = Array.isArray(data) ? data : [];
        this.columns = Array.isArray(columns) ? columns : [];
        this.options = { ...defaultOptions, ...options };
        
        if (this.options.stickyHeader) {
            // インスタンスごとにスコープされた sticky スタイルを適用
            const instanceId = 'qa-table-' + Math.random().toString(36).substring(2, 11);
            this.container.setAttribute('data-qa-table-id', instanceId);
            const style = document.createElement('style');
            // topの値は0pxだと後ろの文字がはみ出る現象が発生したため、-2pxに調整
            style.textContent = `
                [data-qa-table-id="${instanceId}"] .qa-table thead th {
                    position: sticky;
                    top: -2px;
                    z-index: 10;
                }
            `;
            document.head.appendChild(style);
        }
        
        this.columnSelections = {};
        
        // 2次元配列の場合はコピーせずに参照を保持してメモリ使用量を削減
        this.filteredData = this.is2DArray ? this.data : [...this.data];
        this.currentPage = 1;
        this.perPage = this.options.perPage;
        this.sortState = [];
        this.filters = [];
        
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

        // 右側のツールボタン群をまとめるコンテナ
        const hasTools = this.options.exportable || this.options.columnToggle;
        if (hasTools) {
            const toolsContainer = document.createElement('div');
            toolsContainer.className = 'qa-table-tools';

            if (this.options.columnToggle) {
                this._restoreColumnVisibility();
                this._createColumnToggleUI(toolsContainer);
            }

            if (this.options.exportable) {
                this._createExportButtons(toolsContainer);
            }

            optionContainer.appendChild(toolsContainer);
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

        // check型カラムの選択状態を初期化
        this.columns.forEach(column => {
            if (column.type === 'check') {
                if (!this.columnSelections[column.key]) {
                    this.columnSelections[column.key] = new Set();
                }
            }
        });

        const thead = document.createElement('thead');
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

        // DOM ツリーに追加後にヘッダーを描画（querySelector で検索可能になる）
        this._renderHeader();
        
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
    
    // ヘッダー描画
    _renderHeader() {
        const table = this.container.querySelector('.qa-table');
        if (!table) return;

        const thead = table.querySelector('thead');
        if (!thead) return;

        thead.innerHTML = '';
        const headerRow = document.createElement('tr');

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
                    // Issue #1175: ユーザーが能動的にソートした印（totalRow.initialTop の判定。初期ソートは含めない）
                    this._userSorted = true;
                    this._handleSort(column.key, isMultiSort);
                });

                th.classList.add('qa-sortable');
            } else {
                th.textContent = column.label || column.key;
                th.classList.add('qa-not-sortable');
            }

            headerRow.appendChild(th);
        });

        thead.appendChild(headerRow);

        // ソートアイコンを復元
        if (this.sortState.length > 0) {
            this._updateSortIcons();
        }
    }

    // ヘッダーとテーブル本体をまとめて再描画
    render() {
        this._renderHeader();
        this._renderTable();
    }

    /**
     * カラムの表示/非表示を切り替える
     * @param {string} columnKey - カラムキー
     * @param {boolean} visible - true で表示、false で非表示
     */
    setColumnVisibility(columnKey, visible) {
        const column = this.columns.find(col => col.key === columnKey);
        if (!column) {
            console.error(this.__('Column not found: ') + columnKey);
            return this;
        }
        column.hidden = !visible;
        this._refreshFilterColumnOptions();
        this._saveColumnVisibility();
        this._updateColumnToggleCheckboxes();
        this.render();
        return this;
    }

    /**
     * 現在表示中のカラム一覧を取得する
     * @returns {Array} 表示中のカラム定義の配列
     */
    getVisibleColumns() {
        return this.columns.filter(col => col.hidden !== true);
    }

    /**
     * 複数カラムの表示/非表示を一括で切り替える
     * @param {Object} map - { columnKey: visible(boolean), ... }
     */
    setColumnsVisibility(map) {
        Object.entries(map).forEach(([key, visible]) => {
            const col = this.columns.find(c => c.key === key);
            if (col) col.hidden = !visible;
        });
        this._refreshFilterColumnOptions();
        this._saveColumnVisibility();
        this._updateColumnToggleCheckboxes();
        this.render();
        return this;
    }

    // --- 列切り替え UI ---

    /**
     * 列切り替えトグル対象のカラム一覧を取得する
     * 以下はトグル対象外:
     * - initialHidden（コンストラクタ時点で hidden: true）
     * - type: 'check'（チェックボックス列）
     */
    _getToggleableColumns() {
        return this.columns.filter(col => !col._initialHidden && col.type !== 'check');
    }

    /**
     * 列切り替え UI を生成する
     */
    _createColumnToggleUI(container) {
        const toggleContainer = document.createElement('div');
        toggleContainer.className = 'qa-column-toggle-container';

        const toggleButton = document.createElement('button');
        toggleButton.className = 'qa-column-toggle-button qa-export-button';
        toggleButton.title = this.__('Column visibility');
        toggleButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg> ' + this.__('Columns');
        toggleButton.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdown = toggleContainer.querySelector('.qa-column-toggle-dropdown');
            if (dropdown) {
                dropdown.classList.toggle('qa-column-toggle-dropdown--open');
            }
        });

        const dropdown = document.createElement('div');
        dropdown.className = 'qa-column-toggle-dropdown';

        const toggleableColumns = this._getToggleableColumns();
        toggleableColumns.forEach(column => {
            const label = document.createElement('label');
            label.className = 'qa-column-toggle-item';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = column.hidden !== true;
            checkbox.dataset.columnKey = column.key;
            checkbox.addEventListener('change', () => {
                this.setColumnVisibility(column.key, checkbox.checked);
            });

            const text = document.createElement('span');
            text.textContent = column.label || column.key;

            label.appendChild(checkbox);
            label.appendChild(text);
            dropdown.appendChild(label);
        });

        toggleContainer.appendChild(toggleButton);
        toggleContainer.appendChild(dropdown);
        container.appendChild(toggleContainer);

        this.columnToggleContainer = toggleContainer;

        // ドロップダウン外をクリックしたら閉じる
        document.addEventListener('click', (e) => {
            if (!toggleContainer.contains(e.target)) {
                const dd = toggleContainer.querySelector('.qa-column-toggle-dropdown');
                if (dd) dd.classList.remove('qa-column-toggle-dropdown--open');
            }
        });
    }

    /**
     * 列切り替え UI のチェックボックス状態を同期する
     */
    _updateColumnToggleCheckboxes() {
        if (!this.columnToggleContainer) return;
        const checkboxes = this.columnToggleContainer.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            const col = this.columns.find(c => c.key === cb.dataset.columnKey);
            if (col) cb.checked = col.hidden !== true;
        });
    }

    /**
     * 列の表示/非表示状態を localStorage に保存する
     */
    _saveColumnVisibility() {
        if (!this.options.columnToggle) return;
        const storageKey = this._getStorageKey();
        const state = {};
        this._getToggleableColumns().forEach(col => {
            state[col.key] = col.hidden !== true;
        });
        try {
            localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (e) {
            // localStorage が使えない環境では無視
        }
    }

    /**
     * localStorage から列の表示/非表示状態を復元する
     */
    _restoreColumnVisibility() {
        // initialHidden を記録（トグル対象外の判別に使用）
        this.columns.forEach(col => {
            col._initialHidden = col.hidden === true;
        });

        const storageKey = this._getStorageKey();
        try {
            const saved = localStorage.getItem(storageKey);
            if (!saved) return;
            const state = JSON.parse(saved);
            Object.entries(state).forEach(([key, visible]) => {
                const col = this.columns.find(c => c.key === key);
                if (col && !col._initialHidden) {
                    col.hidden = !visible;
                }
            });
        } catch (e) {
            // パースエラー時は無視
        }
    }

    /**
     * localStorage のキーを生成する
     */
    _getStorageKey() {
        const selectorId = typeof this.container.id === 'string' && this.container.id
            ? this.container.id
            : this.container.className;
        return 'qa-table-columns-' + selectorId;
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

        // Issue #1175: 合計行を「ソート対象の1行」としてリストに差し込む（固定しない）
        // 集計は filteredData 全体から（フィルタ反映）。位置はソート順に従う。
        let renderRows = this.filteredData;
        if (this.options.totalRow && this.filteredData.length > 0) {
            this._totalRow = this._buildTotalRow();
            this._syncTotalCheck();
            renderRows = this._withTotalRow(this.filteredData);
        } else {
            this._totalRow = null;
        }

        let displayData = renderRows;
        if (this.options.pagination) {
            const startIndex = (this.currentPage - 1) * this.perPage;
            const endIndex = startIndex + this.perPage;
            displayData = renderRows.slice(startIndex, endIndex);
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
            let totalColumnCount = this._getVisibleColumnCount();
            
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

            // Issue #1175: 合計行(_rowId=0)は再採番しない（0 は falsy なので === undefined で判定）
            if (rowData._rowId === undefined || rowData._rowId === null) {
                rowData._rowId = rowIndex + 1;
            }
            row.setAttribute('data-row-id', String(rowData._rowId));
            if (rowData._isTotal) {
                row.classList.add('qa-total-row');
            }

            
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

                    // Issue #1175: 合計行のチェックは totalRow.selectable のときだけ描く（既定は空セル）
                    const totalNotSelectable = rowData._isTotal &&
                        !(this.options.totalRow && typeof this.options.totalRow === 'object' && this.options.totalRow.selectable);
                    if (totalNotSelectable) {
                        cell.classList.add('qa-column-check');
                        row.appendChild(cell);
                        return;
                    }

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
                        // Issue #1175: 合計行のチェック状態は _totalChecked を正とする（updateData 後も復元）
                        if (rowData._isTotal) this._totalChecked = checkbox.checked;
                        this._handleRowSelection(rowData._rowId, checkbox.checked, column.key);
                    });

                    cell.appendChild(checkbox);
                    cell.classList.add('qa-column-check');
                } else {
                    // Issue #1453 / W-5: priority chain (formatter > render > type > raw) via _formatCell.
                    const fc = this._formatCell(column, value, rowIndex, rowData);
                    if (fc.kind === 'html') {
                        cell.innerHTML = fc.content;
                        // Add type-based class only when the type branch produced the output.
                        if (fc.typeClass) {
                            cell.classList.add(fc.typeClass);
                        }
                    } else {
                        cell.textContent = fc.content !== undefined && fc.content !== null ? fc.content : '';
                    }
                }
            
                
                // Issue #1403: opt-in per-cell class hook (value-dependent cell coloring).
                // Runs only when a column defines cellClass(); otherwise the cell is byte-identical to before.
                if (typeof column.cellClass === 'function') {
                    const cellCls = column.cellClass(value, rowData, rowIndex);
                    if (cellCls) {
                        cell.classList.add(cellCls);
                    }
                }

                if (typeof column.textAlign !== 'undefined') {
                    cell.style.textAlign = column.textAlign;
                }
                row.appendChild(cell);
            });

            // Issue #1386: 行クリック選択（onRowClick 指定時のみ・実データ行に限定）。
            // 合計行(_isTotal)は除外。操作セル（リンク/チェックボックス/ボタン/入力）発の
            // クリック・キーは行選択に伝播させない（既存のセル機能を壊さない）。
            if (typeof this.options.onRowClick === 'function' && !rowData._isTotal) {
                const interactiveSel = 'a, button, input, select, textarea, label';
                const isInteractiveTarget = (ev) =>
                    ev.target && typeof ev.target.closest === 'function' && ev.target.closest(interactiveSel);
                row.classList.add('qa-row-clickable');
                row.setAttribute('tabindex', '0');
                row.setAttribute('role', 'button');
                row.addEventListener('click', (ev) => {
                    if (isInteractiveTarget(ev)) return;
                    this.options.onRowClick(rowData, rowIndex, row);
                });
                row.addEventListener('keydown', (ev) => {
                    if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') return;
                    if (isInteractiveTarget(ev)) return;
                    ev.preventDefault();
                    this.options.onRowClick(rowData, rowIndex, row);
                });
            }

            tbody.appendChild(row);
        });

        // Issue #1175: 合計行は displayData にソート対象の1行として含めて描画済み（固定挿入はしない）

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
                // Issue #1453 PR-E2: 実効 native 型（filterAs 解決）で演算子集合を選ぶ。
                const colType = (this._effectiveFilterType(selectedColumn) || '').toLowerCase();
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
    
    // フィルタのカラム選択肢を現在の columns 状態に同期する
    _refreshFilterColumnOptions() {
        if (!this.filterContainer) return;
        const columnSelect = this.filterContainer.querySelector('.qa-filter-column');
        if (!columnSelect) return;

        const currentValue = columnSelect.value;
        columnSelect.innerHTML = '';

        this.columns.forEach(column => {
            if (column.hidden === true || column.filtering === false) {
                return;
            }
            const option = document.createElement('option');
            option.value = column.key;
            option.textContent = column.label || column.key;
            columnSelect.appendChild(option);
        });

        // 以前の選択値が残っていれば復元
        if (currentValue && columnSelect.querySelector(`option[value="${currentValue}"]`)) {
            columnSelect.value = currentValue;
        }

        // フィルタタイプの選択肢も更新
        columnSelect.dispatchEvent(new Event('change'));
    }

    // フィルタ一覧を更新
    _updateFilterList() {
        if (!this.filterList) return;
        
        this.filterList.innerHTML = '';
        
        if (this.filters.length === 0) {
            return;
        }
        
        const tagsContainer = document.createElement('div');
        tagsContainer.className = 'qa-filter-tags';
        
        this.filters.forEach((filter, index) => {
            const filterTag = document.createElement('div');
            filterTag.className = 'qa-filter-tag';
            
            const column = this.columns.find(col => col.key === filter.column);
            const columnName = column ? (column.label || column.key) : filter.column;
            
            // Issue #1453 PR-E2: 実効 native 型（filterAs 解決）でフィルタタグの型名を引く。
            const columnType = (this._effectiveFilterType(column) || '').toLowerCase() || 'default';
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
        this.filteredData = this.is2DArray ? this.data : [...this.data];
        this.currentPage = 1;
        this._renderTable();
        
        return this;
    }
    
    // フィルタ適用
    _applyFilters() {
        if (this.filters.length === 0) {
            this.filteredData = this.is2DArray ? this.data : [...this.data];
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
                // Issue #1453 PR-E2: 実効 native 型（sortAs 解決）。既定型は type 名自身。
                const columnType = columnDef ? this._effectiveSortType(columnDef) : null;

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
            
            this.filteredData.sort((a, b) => this._compareBySortState(a, b));
        } catch (error) {
            console.error(this.__('Error occurred during sorting:'), error);
            this.filteredData = originalData;
        }
    }

    /**
     * this.sortState に基づく行比較。_applySorting と合計行の挿入位置算出で共用する。
     * @returns {number} a が先なら負、b が先なら正、同値なら 0
     */
    _compareBySortState(a, b) {
        if (!a || !b) return 0;

        for (const sortItem of this.sortState) {
            const { column, direction } = sortItem;

            const columnDef = this.columns.find(col => col.key === column);
            // Issue #1453 PR-E2: 実効 native 型（sortAs 解決・duration 特例判定もこれに従う）。
            const columnType = columnDef ? this._effectiveSortType(columnDef) : null;

            let valueA = this._getDataValue(a, column);
            let valueB = this._getDataValue(b, column);

            if (valueA === null || valueA === undefined) valueA = '';
            if (valueB === null || valueB === undefined) valueB = '';

            if (columnType === 'duration') {
                const numA = typeof valueA === 'number' ? valueA : this._parseDurationToSeconds(valueA);
                const numB = typeof valueB === 'number' ? valueB : this._parseDurationToSeconds(valueB);
                if (numA !== numB) {
                    return direction === 'asc' ? numA - numB : numB - numA;
                }
            }
            else if (typeof valueA === 'number' && typeof valueB === 'number') {
                if (valueA !== valueB) {
                    return direction === 'asc' ? valueA - valueB : valueB - valueA;
                }
            }
            else if (valueA instanceof Date && valueB instanceof Date) {
                const timeA = valueA.getTime();
                const timeB = valueB.getTime();
                if (timeA !== timeB) {
                    return direction === 'asc' ? timeA - timeB : timeB - timeA;
                }
            }
            else {
                const strA = String(valueA).toLowerCase();
                const strB = String(valueB).toLowerCase();
                if (strA !== strB) {
                    return direction === 'asc' ? strA.localeCompare(strB) : strB.localeCompare(strA);
                }
            }
        }
        return 0;
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

            const headers = this.container.querySelectorAll('.qa-sortable');
            const visibleIndex = this._getVisibleColumnIndex(sortItem.column);
            const targetHeader = visibleIndex >= 0 ? headers[visibleIndex] : null;

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
        
        const visibleColumnIndex = this._getVisibleColumnIndex(columnKey);

        if (visibleColumnIndex === -1) {
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
     * チェック行IDから行データを引く。Issue #1175: rowId=0 は合計行（this.data 外）を返す。
     */
    _findCheckedRow(numericRowId) {
        if (numericRowId === 0 && this._totalRow) return this._totalRow;
        return this.data.find(item => {
            const itemRowId = typeof item._rowId === 'string' ? parseInt(item._rowId, 10) : item._rowId;
            return itemRowId === numericRowId;
        });
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
                // 対応するデータオブジェクトを検索（rowId=0 は合計行）
                const rowData = this._findCheckedRow(rowId);

                if (rowData) {
                    // 内部プロパティを除外した新しいオブジェクトを作成
                    const cleanData = {};
                    Object.keys(rowData).forEach(key => {
                        if (key !== '_rowId' && key !== '_isTotal') {
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
                
                // 対応するデータオブジェクトを検索（rowId=0 は合計行）
                const rowData = this._findCheckedRow(numericRowId);

                if (rowData) {
                    // 内部プロパティを除外した新しいオブジェクトを作成
                    const cleanData = {};
                    Object.keys(rowData).forEach(key => {
                        if (key !== '_rowId' && key !== '_isTotal') {
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
                
                // 対応するデータオブジェクトを検索（rowId=0 は合計行）
                const rowData = this._findCheckedRow(numericRowId);

                if (rowData) {
                    // 内部プロパティを除外した新しいオブジェクトを作成
                    const cleanData = {};
                    Object.keys(rowData).forEach(key => {
                        if (key !== '_rowId' && key !== '_isTotal') {
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

    /**
     * HTML 特殊文字をエスケープして innerHTML 挿入を安全化する（Issue #1299）。
     * PR-E2（#1453 / C3-6・専属🟡-3）: 実体は static QATable.escapeHtml に一本化し、
     * インスタンス経由の既存呼び出し（this._escapeHtml）はそこへ委譲する。
     * これにより adapter 等の外部コードが自前 escape を重複させず、存在ガード付きで
     * QATable.escapeHtml を参照できる（qa-table 不在環境では自前 escape に安全劣化）。
     */
    _escapeHtml(value) {
        return QATable.escapeHtml(value);
    }

    /**
     * URL のスキームを許可リスト（http/https/mailto）で検証する（Issue #1299）。
     * 実体は static QATable.sanitizeUrl（PR-E2 / C3-6）。this._sanitizeUrl は委譲。
     */
    _sanitizeUrl(value) {
        return QATable.sanitizeUrl(value);
    }

    /**
     * HTML 特殊文字エスケープの実体（static 公開・Issue #1299 / #1453 PR-E2）。
     */
    static escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * URL スキーム検証の実体（static 公開・Issue #1299 / #1453 PR-E2）。
     * 許可外スキーム（javascript:, data:, vbscript: 等）は '#' を返す。
     * スキーム無し（相対 / アンカー / プロトコル相対）の URL は許可する。
     */
    static sanitizeUrl(value) {
        const raw = String(value).replace(/[\t\n\r\f\v\0]/g, '').trim();
        const m = raw.match(/^([a-z][a-z0-9+.\-]*):/i);
        if (m) {
            const scheme = m[1].toLowerCase();
            if (scheme !== 'http' && scheme !== 'https' && scheme !== 'mailto') {
                return '#';
            }
        }
        return raw;
    }

    // エクスポート機能
    /**
     * 値をカラムタイプに基づいてフォーマットする
     * @param {*} value - フォーマットする値
     * @param {string} type - カラムタイプ (QA_COLUMN_TYPES から)
     * @param {Object} options - フォーマットオプション
     * @returns {string} - フォーマットされた値
     */
    // Single source for the cell-formatting priority chain (Issue #1453 / W-5 PR-E1).
    // Decides formatter > render > type(_formatByType) > raw, once, so render / CSV /
    // JSON / total-row export stop re-implementing it (they had drifted — e.g. the
    // total-row export skips the formatter while the on-screen total row applies it).
    //
    // Returns { kind, content, typeClass? }:
    //   kind='html'  : content is an HTML string (formatter / render / _formatByType output).
    //                  Render assigns it via innerHTML; export strips it. typeClass is
    //                  'qa-column-<type>' only when the type branch produced it (render adds it).
    //   kind='text'  : content is the RAW value, unchanged. The caller shapes it — render uses
    //                  textContent with a null/undefined -> '' coalesce, CSV keeps it raw, JSON
    //                  keeps its native type. This asymmetry is intentional (bug-for-bug).
    //
    // What stays with the caller (it differs per site, so folding it in would change bytes):
    // value extraction (rowData vs _getDataValue), HTML stripping for export, the null->''
    // coalesce, and the color-threshold cellClass hook (#1403, runs after, on every path).
    //
    // opts.applyFormatter (default true): when false, formatter/render are skipped and the chain
    // starts at the type branch. The total-row export path passes false to preserve its current
    // "type only" behavior.
    //
    // ⚠️ Caller contract (Issue #1453 PR-E2 / PR-E1 ⚪-1): with applyFormatter defaulting to true,
    // a caller that passes row=null for a column that HAS a formatter/render will invoke
    // formatter(value, null, rowIndex) — a formatter that dereferences its row arg would throw.
    // Current callers that pass row=null (total-row export) also pass applyFormatter:false, so the
    // formatter/render branches are skipped and no such caller exists today. Any future caller that
    // passes row=null while leaving applyFormatter true must ensure the column has no formatter/render.
    _formatCell(column, value, rowIndex, row, opts) {
        const applyFormatter = !opts || opts.applyFormatter !== false;
        if (applyFormatter && typeof column.formatter === 'function' && !this._htmlFormatterIgnored(column)) {
            return { kind: 'html', content: column.formatter(value, row, rowIndex) };
        }
        if (applyFormatter && typeof column.render === 'function' && !this._htmlFormatterIgnored(column)) {
            return { kind: 'html', content: column.render(value, row, rowIndex) };
        }
        if (column.type) {
            // typeClass reflects a BUILT-IN (default-registry) type only. Instance-type overrides
            // (options.types — e.g. the assistant adapter's integer/percentage/scorebar/… types)
            // intentionally emit NO class, preserving pre-E2 parity: those columns previously carried
            // a column.formatter and so went through the formatter branch above (which never sets a
            // typeClass), not the type branch.
            const isInstanceType = this.options && this.options.types &&
                Object.prototype.hasOwnProperty.call(this.options.types, column.type);
            return {
                kind: 'html',
                content: this._formatByType(value, column.type, column.typeOptions),
                typeClass: isInstanceType ? undefined : 'qa-column-' + column.type
            };
        }
        return { kind: 'text', content: value };
    }

    // Issue #1453 / W-5 PR-E2: value formatting is dispatched through the column-type
    // REGISTRY instead of a hard-coded switch. Resolution order = instance override
    // (this.options.types) > global default registry (QATable._defaultTypes). The default
    // registry is seeded (below the class) with every type the old switch handled, byte-identical.
    // An unknown type (in neither registry) falls back to 'string' handling (escape) and warns
    // once per type name — surfacing the previously-silent default branch (暗黙契約 C3-4）.
    _formatByType(value, type, options) {
        options = options || {};

        // Instance override (this.options.types) reproduces the pre-E2 column.formatter path,
        // which had NO empty-value guard: the format function itself handles ''/null/undefined
        // (e.g. the adapter's integer formatter returns escapeHtml(value), so null -> "null").
        // Keeping the guard OFF here preserves that byte-for-byte. Built-in (default-registry)
        // types keep the historical guard below, so standalone qa-table is unchanged.
        const inst = this.options && this.options.types;
        if (inst && Object.prototype.hasOwnProperty.call(inst, type) &&
            inst[type] && typeof inst[type].format === 'function') {
            return inst[type].format.call(this, value, options);
        }

        if (value === undefined || value === null || value === '') {
            return '';
        }

        if (Object.prototype.hasOwnProperty.call(QATable._defaultTypes, type) &&
            typeof QATable._defaultTypes[type].format === 'function') {
            return QATable._defaultTypes[type].format.call(this, value, options);
        }

        this._warnUnknownType(type);
        return this._escapeHtml(value);
    }

    /**
     * 列 type 定義を解決する（Issue #1453 PR-E2）。
     * インスタンス上書き（this.options.types）を最優先し、無ければグローバル既定
     * レジストリ（QATable._defaultTypes）を引く。どちらにも無ければ null。
     * hasOwnProperty で引くのは Object.prototype 由来のキー（'toString' 等）を型と誤認しないため。
     */
    _resolveTypeEntry(type) {
        const inst = this.options && this.options.types;
        if (inst && Object.prototype.hasOwnProperty.call(inst, type)) {
            return inst[type];
        }
        if (Object.prototype.hasOwnProperty.call(QATable._defaultTypes, type)) {
            return QATable._defaultTypes[type];
        }
        return null;
    }

    /**
     * ソート比較で使う「実効 native 型」を返す（Issue #1453 PR-E2）。
     * レジストリ定義の sortAs があればそれを、無ければ列の type をそのまま返す。
     * 既定レジストリの型は sortAs 未指定＝type 名自身に落ちるので単独利用の挙動は不変。
     * アダプタのインスタンス型（percentage 等）は sortAs で従来 TYPE_MAP の native 型へ写像し、
     * ソート順のバイト等価を保つ（例: duration→'string' で数値ソート化を防ぐ）。
     */
    _effectiveSortType(column) {
        if (!column || !column.type) return column ? column.type : null;
        const entry = this._resolveTypeEntry(column.type);
        return (entry && entry.sortAs) ? entry.sortAs : column.type;
    }

    /**
     * フィルタ UI の演算子選択で使う「実効 native 型」を返す（Issue #1453 PR-E2）。
     * filterAs があればそれを、無ければ列の type。既定レジストリの型は type 名自身に落ちる。
     */
    _effectiveFilterType(column) {
        if (!column || !column.type) return '';
        const entry = this._resolveTypeEntry(column.type);
        return (entry && entry.filterAs) ? entry.filterAs : column.type;
    }

    /**
     * 未知 type（レジストリ未登録）を型名ごとに1回だけ warn する（Issue #1453 PR-E2 / C3-4）。
     */
    _warnUnknownType(type) {
        if (!QATable._unknownTypeWarned) QATable._unknownTypeWarned = {};
        if (QATable._unknownTypeWarned[type]) return;
        QATable._unknownTypeWarned[type] = true;
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[qa-table] unknown column type "' + type + '"; rendered as escaped string.');
        }
    }
    
    _createExportButtons(container) {
        if (!this.options.exportable) return;
        
        const exportContainer = document.createElement('div');
        exportContainer.className = 'qa-export-container';
        
        const csvButton = document.createElement('button');
        csvButton.className = 'qa-export-button qa-export-csv';
        csvButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> CSV';
        csvButton.title = this.__('CSV Export');
        csvButton.addEventListener('click', () => {
            this.exportToCSV();
        });

        const jsonButton = document.createElement('button');
        jsonButton.className = 'qa-export-button qa-export-json';
        jsonButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> JSON';
        jsonButton.title = this.__('JSON Export');
        jsonButton.addEventListener('click', () => {
            this.exportToJSON();
        });
        
        exportContainer.appendChild(csvButton);
        exportContainer.appendChild(jsonButton);
        
        container.appendChild(exportContainer);
    }
    
    // formatter / render / type='link'|'html' は innerHTML 用に HTML 文字列を返すため、
    // CSV/JSON にそのまま流すと <a href="..."> が出力に混入する（Issue #1160）。
    // DOMParser の inert document でタグ除去 + エンティティデコードを行う（#1438）。
    // inert document はリソースロードもイベントハンドラも一切走らないため、旧実装
    // （detached div への innerHTML 代入）で原理上ありえた <img onerror> 等の発火が
    // 構造的に起きない。既存の formatter/type が返す出力形状（要素・平文始まり）では
    // 旧実装とテキスト同一（先頭 <style> 等の head 系要素・先頭空白のような極端入力
    // のみ完全文書パースがよりクリーンなテキストになる＝該当する現存出力なし）。
    _stripHtmlForExport(value) {
        if (value === undefined || value === null) return '';
        const str = String(value);
        if (!/<[^>]+>|&[a-zA-Z#0-9]+;/.test(str)) return str;
        if (!this._exportStripParser) {
            this._exportStripParser = new DOMParser();
        }
        return this._exportStripParser.parseFromString(str, 'text/html').body.textContent || '';
    }

    // 「html 列に formatter/render を張らない」は従来コメントだけの約束で、破ると優先チェーン
    // （formatter > render > type）が case 'html' のサニタイズを警告なしに素通りさせていた。
    // html 列では formatter/render を無視して必ずサニタイズ経路を通す（#1438・列ごとに1回 warn）。
    _htmlFormatterIgnored(column) {
        if (column.type !== 'html') return false;
        if (typeof column.formatter !== 'function' && typeof column.render !== 'function') return false;
        if (!this._htmlFormatterWarned) this._htmlFormatterWarned = {};
        if (!this._htmlFormatterWarned[column.key] && typeof console !== 'undefined' && console.warn) {
            this._htmlFormatterWarned[column.key] = true;
            console.warn('[qa-table] column "' + column.key + '": formatter/render is ignored on type:"html" columns (sanitization cannot be bypassed).');
        }
        return true;
    }

    exportToCSV(filename = 'table-export.csv') {
		const exportableColumns = this.columns.filter(column =>
            column.hidden !== true && column.exportable !== false
        );

        const headers = exportableColumns.map(column => column.label || column.key);

        const rows = this.filteredData.map((item, rowIndex) => {
            return exportableColumns.map(column => {
                // Issue #1453 / W-5: priority chain via _formatCell (kind='text' returns the raw value).
                const fc = this._formatCell(column, this._getDataValue(item, column.key), rowIndex, item);
                let value = fc.content;

                value = this._stripHtmlForExport(value);

                // CSVエスケープ処理
                if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                    value = '"' + value.replace(/"/g, '""') + '"';
                }

                return value;
            }).join(',');
        });

        // Issue #1175: 合計行を表示しているときはエクスポートにも含める（ヘッダ直後＝表の見た目どおり一番上）
        const csvLines = [headers.join(',')];
        if (this._isTotalRowInExport()) {
            csvLines.push(this._buildTotalExportRowCsv(exportableColumns));
        }
        csvLines.push(...rows);
        const csvContent = csvLines.join('\n');
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
                // Issue #1453 / W-5: priority chain via _formatCell (kind='text' returns the raw value,
                // keeping its native type so JSON preserves numbers / booleans below).
                const fc = this._formatCell(column, this._getDataValue(item, column.key), rowIndex, item);
                let value = fc.content;

                // 数値・boolean 等の非文字列値は JSON 上の型を保持するためそのまま残す。
                // formatter / render / _formatByType の戻り値は文字列なのでここで HTML 除去。
                if (typeof value === 'string') {
                    value = this._stripHtmlForExport(value);
                }

                exportItem[column.label] = value;
            });

            return exportItem;
        });

        // Issue #1175: 合計行を表示しているときはエクスポートにも含める（配列先頭＝表の見た目どおり一番上）
        if (this._isTotalRowInExport()) {
            const totalObj = this._buildTotalExportObjectJson(exportableColumns);
            if (totalObj) {
                exportData.unshift(totalObj);
            }
        }
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
        
        // 2次元配列かどうかを判定（インスタンスプロパティを更新）
        this.is2DArray = newData.length > 0 && Array.isArray(newData[0]);

        // データを更新（2次元配列の場合はコピーせずに参照を保持してメモリ使用量を削減）
        this.data = newData;
        this.filteredData = this.is2DArray ? newData : [...newData];

        this.filteredData.forEach((row, index) => {
            if (this.is2DArray) {
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

        // フィルタを適用（フィルタがある場合）
        if (this.filters.length > 0) {
            this._applyFilters();
        }

        // ソートを適用（ソート状態がある場合）
        if (this.sortState.length > 0) {
            this._applySorting();
        }

        // Issue #1175: 合計行データとチェックを「同期で」用意する。
        // updateData は check 列を作り直す＋_renderTable を setTimeout で遅延するため、
        // ここで _totalRow を作りチェックを復元しておかないと、直後の getCheckedData
        // （グラフ初期描画）が合計行を拾えず全体推移が描画されない。
        if (this.options.totalRow && this.filteredData.length > 0) {
            this._totalRow = this._buildTotalRow();
        } else {
            this._totalRow = null;
        }
        this._syncTotalCheck();

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
            const columnIndex = typeof key === 'number' ? key : this.columns.findIndex(col => col.key === key);
            return columnIndex >= 0 && columnIndex < item.length ? item[columnIndex] : undefined;
        }
        // オブジェクト形式のデータ処理
        return item[key];
    }

    /**
     * 非表示カラムを考慮した表示上のカラムインデックスを取得する
     * @param {string} columnKey - カラムキー
     * @returns {number} - 表示上のインデックス（非表示カラムの場合は -1）
     */
    _getVisibleColumnIndex(columnKey) {
        let visibleIndex = 0;
        for (let i = 0; i < this.columns.length; i++) {
            if (this.columns[i].hidden === true) continue;
            if (this.columns[i].key === columnKey) {
                return visibleIndex;
            }
            visibleIndex++;
        }
        return -1;
    }

    /**
     * 表示中のカラム数を取得する
     * @returns {number} - 表示中のカラム数
     */
    _getVisibleColumnCount() {
        return this.columns.filter(col => col.hidden !== true).length;
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
    // ===== Issue #1175: 合計行（totalRow）。既定オフ・呼び出し側で totalRow:true でオプトイン =====

    /**
     * 列ごとの集計方法を決定する。
     * - agg が明示されていればそれを使う（'sum' | 'avg' | 'wavg' | 'count' | 'none' | {type, weightKey}）
     * - 未指定なら type から自動判定（数値=sum / 率・時間=加重平均 / その他=集計しない）
     * 加重平均の母数は agg.weightKey > options.totalRow.weightKey の順で解決。母数が無ければ単純平均にフォールバック。
     * @returns {{type:string, weightKey?:string}|null} 集計しない列は null
     */
    _resolveColumnAgg(column) {
        if (!column || typeof column.key === 'function') return null;
        if (column.type === 'check') return null;

        const tr = this.options.totalRow;
        const tableWeightKey = (tr && typeof tr === 'object') ? tr.weightKey : undefined;

        let agg = column.agg;
        if (agg === 'none' || agg === false || agg === null) return null;
        if (typeof agg === 'string') agg = { type: agg };

        if (agg && typeof agg === 'object' && agg.type) {
            if (agg.type === 'wavg') {
                const wk = agg.weightKey || tableWeightKey;
                return wk ? { type: 'wavg', weightKey: wk } : { type: 'avg' };
            }
            if (agg.type === 'derived') {
                // 他列の合計から比率等を再計算する派生集計（compute(totals, rows) を 2 パス目で実行）
                return (typeof agg.compute === 'function') ? { type: 'derived', compute: agg.compute } : null;
            }
            return { type: agg.type };
        }

        // 自動判定（agg 未指定）
        switch (column.type) {
            case 'number':
            case 'integer':
            case 'float':
            case 'currency':
            case 'filesize':
                return { type: 'sum' };
            case 'percentage':
            case 'duration':
                return tableWeightKey ? { type: 'wavg', weightKey: tableWeightKey } : { type: 'avg' };
            default:
                return null; // string / date / datetime / boolean / link 等は集計しない（空欄）
        }
    }

    /**
     * 集計用に値を数値化する。duration は秒数へ、それ以外は数値抽出。集計不能なら null。
     */
    _toAggNumber(value, type) {
        if (value === undefined || value === null || value === '') return null;
        if (type === 'duration') {
            const sec = this._parseDurationToSeconds(value);
            return (typeof sec === 'number' && !isNaN(sec)) ? sec : null;
        }
        const n = (typeof value === 'number') ? value : parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? null : n;
    }

    /**
     * 表示中の filteredData 全体（ページネーション無視）から列ごとの集計値を求める。
     * @returns {Object} { columnKey: 集計値 or null }
     */
    _calculateTotals() {
        const rows = Array.isArray(this.filteredData) ? this.filteredData : [];
        const totals = {};
        const derivedCols = [];

        this.columns.forEach(column => {
            const agg = this._resolveColumnAgg(column);
            if (!agg) return;
            const key = column.key;

            if (agg.type === 'derived') {
                // 他列の合計に依存するため 2 パス目で計算する
                derivedCols.push({ key: key, agg: agg });
                return;
            }
            if (agg.type === 'count') {
                totals[key] = rows.length;
                return;
            }
            if (agg.type === 'sum' || agg.type === 'avg') {
                let sum = 0, count = 0;
                rows.forEach(r => {
                    const v = this._toAggNumber(this._getDataValue(r, key), column.type);
                    if (v !== null) { sum += v; count++; }
                });
                totals[key] = (count === 0) ? null : ((agg.type === 'avg') ? (sum / count) : sum);
                return;
            }
            if (agg.type === 'wavg') {
                const wType = this._getColumnType(agg.weightKey);
                let num = 0, den = 0;
                rows.forEach(r => {
                    const v = this._toAggNumber(this._getDataValue(r, key), column.type);
                    const w = this._toAggNumber(this._getDataValue(r, agg.weightKey), wType);
                    if (v !== null && w !== null && w > 0) { num += v * w; den += w; }
                });
                totals[key] = den > 0 ? (num / den) : null;
                return;
            }
            console.warn(this.__('Unknown aggregation type: ') + agg.type);
        });

        // 2 パス目: 派生集計（他列の合計値 totals を参照して比率等を再計算する）
        derivedCols.forEach(item => {
            let v = null;
            try {
                v = item.agg.compute(totals, rows);
            } catch (e) {
                v = null;
            }
            totals[item.key] = (typeof v === 'number' && isFinite(v)) ? v : null;
        });

        return totals;
    }

    /**
     * 合計行のラベル（最左の集計しない列に入れる文字列）。
     */
    _totalRowLabel() {
        const tr = this.options.totalRow;
        if (tr && typeof tr === 'object' && tr.label) return tr.label;
        return this.__('Total');
    }

    /**
     * 合計行を「1つの行」として組み立てる（ソート対象・固定しない）。
     * データ行と同じ形（配列／オブジェクト）で返し、各セルに集計値・ラベル・固定値を入れる。
     * _rowId=0（通常行は1始まりなので衝突しない）・_isTotal=true を持つ。
     */
    _buildTotalRow() {
        if (!this.options.totalRow) return null;
        const rows = Array.isArray(this.filteredData) ? this.filteredData : [];
        if (rows.length === 0) return null;

        const totals = this._calculateTotals();
        const label = this._totalRowLabel();
        const tr = this.options.totalRow;
        const fixed = (tr && typeof tr === 'object' && tr.values) ? tr.values : {};
        let labelPlaced = false;

        // 列順にセル値を決める（hidden 列も配列インデックスを保つため埋める）
        const cells = this.columns.map(column => {
            if (typeof column.key === 'function') return '';
            const key = column.key;
            if (Object.prototype.hasOwnProperty.call(fixed, key)) return fixed[key];
            if (column.type === 'check') return false; // チェックは columnSelections から描画
            const agg = this._resolveColumnAgg(column);
            const val = totals ? totals[key] : undefined;
            if (agg && val !== null && val !== undefined) return val;
            // 最左の「表示中・非集計・非固定」列にラベルを入れる
            if (column.hidden !== true && !labelPlaced) { labelPlaced = true; return label; }
            return '';
        });

        let row;
        if (Array.isArray(rows[0])) {
            row = cells.slice();
        } else {
            row = {};
            this.columns.forEach((column, i) => {
                if (typeof column.key !== 'function') row[column.key] = cells[i];
            });
        }
        row._rowId = 0;
        row._isTotal = true;
        return row;
    }

    /**
     * 合計行をソート済みデータの正しい位置に差し込んだ新配列を返す（元配列は変更しない）。
     * ソート未指定なら先頭。チェック列ソート時はチェック状態で上下に置く。
     */
    _withTotalRow(sortedData) {
        const total = this._totalRow;
        if (!total) return sortedData;

        // Issue #1175: initialTop = 初期表示（ユーザーが未ソート）の間は合計を最上段に置く。
        // 言語非依存（文字列ソートに依存しない）。ユーザーが列ヘッダでソートしたら下の通常処理で動く。
        const tr = this.options.totalRow;
        if (tr && typeof tr === 'object' && tr.initialTop && !this._userSorted) {
            return [total].concat(sortedData);
        }

        if (!this.sortState || this.sortState.length === 0) return [total].concat(sortedData);

        const primary = this.sortState[0];
        const col = this.columns.find(c => c.key === primary.column);
        if (col && col.type === 'check') {
            const checkKey = primary.column;
            const checked = this.columnSelections[checkKey] && this.columnSelections[checkKey].has(0);
            if (primary.direction === 'asc') {
                return checked ? sortedData.concat([total]) : [total].concat(sortedData);
            }
            return checked ? [total].concat(sortedData) : sortedData.concat([total]);
        }

        let idx = sortedData.length;
        for (let i = 0; i < sortedData.length; i++) {
            if (this._compareBySortState(total, sortedData[i]) <= 0) { idx = i; break; }
        }
        const out = sortedData.slice();
        out.splice(idx, 0, total);
        return out;
    }

    /**
     * selectable な合計行のチェック状態を columnSelections に同期する。
     * updateData が check 列を毎回作り直して合計行(0)を落とすため、描画ごとに呼んで復元する。
     * 状態の正は this._totalChecked（既定は checkedByDefault・ユーザー操作で更新）。
     *
     * maxSelections ポリシー（明示）:
     * 合計行(0)は maxSelections の「1枠」として数える（合計が通常データ行だった旧仕様と同じ＝系列数の上限は不変）。
     * 既定では合計のみがチェック済み・データ行は未チェックなので、合計は常に最初の1枠を占める。
     * ユーザーはそこからデータ行を最大 (max-1) 件まで選べる（cap 到達でデータ行 checkbox が disabled になる）。
     * ゴール切替などの再描画ではデータ行のチェックを先に seed するが、再チェックされるのはデータ行のみ
     * （合計はデータ行ではなく名前一致もしない）ため、データ行だけで cap が埋まり合計が押し出される状態は
     * UI から発生しない。下の防御ガードはその理論上の超過のみを抑止する（通常フローでは常に合計が保持される）。
     */
    _syncTotalCheck() {
        const tr = this.options.totalRow;
        if (!tr || typeof tr !== 'object' || !tr.selectable) return;
        const checkCol = this.columns.find(c => c.type === 'check');
        if (!checkCol) return;
        if (this._totalChecked === undefined) this._totalChecked = (tr.checkedByDefault !== false);
        if (!this.columnSelections[checkCol.key]) this.columnSelections[checkCol.key] = new Set();
        const sel = this.columnSelections[checkCol.key];
        if (this._totalChecked) {
            // 防御: データ行だけで cap が埋まっている理論上のケースでは合計を足さない（UI からは発生しない）
            const max = checkCol.typeOptions && checkCol.typeOptions.maxSelections;
            if (sel.has(0) || !max || sel.size < max) sel.add(0);
        } else {
            sel.delete(0);
        }
    }

    /**
     * エクスポートに合計行を含めるか。totalRow 有効時は既定で含める（totalRow.includeInExport:false で除外可）。
     */
    _isTotalRowInExport() {
        const tr = this.options.totalRow;
        if (!tr) return false;
        if (typeof tr === 'object' && tr.includeInExport === false) return false;
        return true;
    }

    /**
     * エクスポート用に、合計行のセル値（文字列配列）を列順で組み立てる。
     */
    _buildTotalExportValues(columns) {
        const totals = this._calculateTotals();
        const label = this._totalRowLabel();
        let labelPlaced = false;

        return columns.map(column => {
            const agg = this._resolveColumnAgg(column);
            const val = totals ? totals[column.key] : undefined;
            if (agg && val !== null && val !== undefined) {
                if (column.type && column.type !== 'check') {
                    // Issue #1453 / W-5: total-row export skips formatter/render (applyFormatter:false)
                    // and formats by type only — preserving the divergence from the on-screen total row,
                    // which DOES apply the formatter (it goes through the normal render cell loop).
                    const fc = this._formatCell(column, val, 0, null, { applyFormatter: false });
                    return this._stripHtmlForExport(fc.content);
                }
                return String(val);
            }
            if (!labelPlaced && column.type !== 'check') {
                labelPlaced = true;
                return label;
            }
            return '';
        });
    }

    /**
     * CSV 用の合計行（1 行ぶんの文字列）。
     */
    _buildTotalExportRowCsv(columns) {
        return this._buildTotalExportValues(columns).map(value => {
            if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                return '"' + value.replace(/"/g, '""') + '"';
            }
            return value;
        }).join(',');
    }

    /**
     * JSON 用の合計行オブジェクト（_isTotal:true で識別可能）。
     */
    _buildTotalExportObjectJson(columns) {
        const values = this._buildTotalExportValues(columns);
        const obj = {};
        columns.forEach((column, i) => {
            obj[column.label] = values[i];
        });
        obj._isTotal = true;
        return obj;
    }
}

// ---------------------------------------------------------------------------
// 列 type レジストリ（Issue #1453 / W-5 PR-E2）
//
// グローバル既定レジストリ。旧 _formatByType の switch を型ごとの format 関数に
// そのまま解体したもの（挙動同一）。各 format は QATable インスタンスを this として
// 呼ばれる（_formatByType 内の entry.format.call(this, value, options)）ので、
// this._escapeHtml / this._sanitizeUrl / this.__ 等のインスタンス面をそのまま使える。
//
// sortAs / filterAs は既定型では未指定＝型名自身に落ちる（単独利用の挙動不変）。
// インスタンス上書き（アダプタの options.types）だけがこれらで native 型へ写像し、
// ソート/フィルタのバイト等価を保つ。
// ---------------------------------------------------------------------------
QATable._defaultTypes = {
    number:  { format: function(value) { return Number(value).toLocaleString(); } },
    integer: { format: function(value) { return Number(value).toLocaleString(); } },
    float:   { format: function(value, options) {
        const floatPrecision = options.precision !== undefined ? options.precision : 2;
        return Number(value).toLocaleString(undefined, {
            minimumFractionDigits: floatPrecision,
            maximumFractionDigits: floatPrecision
        });
    } },
    currency: { format: function(value, options) {
        const currency = options.currency || '¥';
        return currency + Number(value).toLocaleString();
    } },
    percentage: { format: function(value, options) {
        const percentPrecision = options.precision !== undefined ? options.precision : 2;
        return Number(value).toLocaleString(undefined, {
            minimumFractionDigits: percentPrecision,
            maximumFractionDigits: percentPrecision
        }) + '%';
    } },
    date: { format: function(value) {
        const date = new Date(value);
        const year = date.getFullYear();
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const day = date.getDate().toString().padStart(2, '0');
        return `${year}/${month}/${day}`;
    } },
    datetime: { format: function(value) {
        return new Date(value).toLocaleString();
    } },
    boolean: { format: function(value, options) {
        return value ? (options.trueLabel || this.__('Yes')) : (options.falseLabel || this.__('No'));
    } },
    duration: { format: function(value) {
        // 秒数を時間表示（HH:MM:SS）に変換
        if (isNaN(value)) return this._escapeHtml(value);
        const seconds = Math.floor(Number(value));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        const fmt = (num) => num.toString().padStart(2, '0');
        return `${fmt(hours)}:${fmt(minutes)}:${fmt(secs)}`;
    } },
    filesize: { format: function(value) {
        // バイト数を適切な単位に変換
        if (isNaN(value)) return this._escapeHtml(value);
        const bytes = Number(value);
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        if (bytes === 0) return '0 B';
        const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const size = (bytes / Math.pow(1024, exponent)).toFixed(2);
        return `${size} ${units[exponent]}`;
    } },
    link: { format: function(value, options) {
        // URLをクリック可能なリンクに変換（Issue #1299: スキーム検証 + HTML エスケープ）
        const url = this._sanitizeUrl(value);
        const text = options.text || String(value);
        const target = options.newTab !== false ? 'target="_blank" rel="noopener noreferrer"' : '';
        return `<a href="${this._escapeHtml(url)}" ${target}>${this._escapeHtml(text)}</a>`;
    } },
    timestamp: { format: function(value) {
        // UNIXタイムスタンプを日時表示に変換
        if (isNaN(value)) return this._escapeHtml(value);
        return new Date(Number(value) * 1000).toLocaleString(); // ミリ秒に変換
    } },
    html: { format: function(value) {
        // HTMLをサニタイズして表示。許可基準は共有ヘルパ qahm.sanitizeHtml() に集約（#1426）。
        // qa-table を単体で外部へ持ち出し、ヘルパも DOMPurify も無い環境では、ヘルパ呼び出しで
        // throw しないよう存在ガードし、自前 escape に安全劣化する（type:html だけ escape 表示・
        // 他 type は無依存動作＝可搬性を保つ。ヘルパ自身も DOMPurify 不在時は同じ escape に落ちる）。
        if (typeof qahm !== 'undefined' && qahm && typeof qahm.sanitizeHtml === 'function') {
            return qahm.sanitizeHtml(value);
        }
        if (!qaTablePurifyWarned && typeof console !== 'undefined' && console.warn) {
            qaTablePurifyWarned = true;
            console.warn('[qa-table] type:"html" requires qahm.sanitizeHtml (DOMPurify); value rendered as escaped text.');
        }
        const tempDiv = document.createElement('div');
        tempDiv.textContent = String(value);
        return tempDiv.innerHTML;
    } },
    // 旧 switch の default（型無し / 未知の既知でない型）＝escape 表示。'string' を明示登録して
    // 「既知の型」に含める（_warnUnknownType は真に未登録の型だけで発火する）。
    string: { format: function(value) { return this._escapeHtml(value); } }
};

// 未知 type の warn を型名ごとに1回だけに抑える状態（インスタンス横断・_warnUnknownType が使う）。
QATable._unknownTypeWarned = {};

/**
 * 列 type をグローバル既定レジストリへ登録/上書きする公開 API（Issue #1453 PR-E2）。
 * def = { format(value, options), sortAs?, filterAs? }。format は必須。
 * ※ アダプタ等の「その表だけ」の型は new QATable(..., { types }) のインスタンス上書きを使うこと
 *   （グローバル登録は qa-table 単独利用の全画面に影響する）。
 * @param {string} name  型名
 * @param {Object} def   型定義（format 必須・sortAs/filterAs 任意）
 */
QATable.registerType = function(name, def) {
    if (typeof name !== 'string' || name === '') {
        throw new Error('QATable.registerType: name must be a non-empty string');
    }
    if (!def || typeof def.format !== 'function') {
        throw new Error('QATable.registerType: def.format must be a function');
    }
    QATable._defaultTypes[name] = def;
};

if (typeof window !== 'undefined') {
    window.qaTable = qaTable;
    // PR-E2（#1453）: 公開 API（QATable.registerType / QATable.escapeHtml / QATable.sanitizeUrl）を
    // クラスとして外部から参照できるよう公開する。アダプタ等が escape/sanitize の重複を持たず
    // 存在ガード付きで QATable.escapeHtml を参照する（C3-6・専属🟡-3）。
    window.QATable = QATable;
}
