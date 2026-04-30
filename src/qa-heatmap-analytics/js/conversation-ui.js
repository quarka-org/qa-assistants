/**
 * QAHM Conversation UI Framework
 * 
 * Generic conversation UI framework for both admin dashboard and frontend chatbot.
 * Extracted from assistant-ai.js to remove admin-specific dependencies.
 * 
 * Key Improvements:
 * - Comprehensive error handling with user feedback
 * - Infinite loop protection for execute arrays
 * - Memory leak prevention through proper cleanup
 * - Security: Removed eval() usage for formatters
 * 
 * @since 1.0.0
 */

var qahm = qahm || {};

qahm.conversationUI = {
    
    /**
     * Default configuration values
     */
    defaults: {
        typewriterSpeed: 15,        // milliseconds per character
        itemDelay: 500,             // milliseconds between execute items
        maxExecuteItems: 100,       // infinite loop protection
        enableTypewriter: true      // toggle typewriter effect
    },

    /**
     * Safe formatter functions (replaces eval)
     * These are pre-defined, trusted formatters
     */
    SAFE_FORMATTERS: {
        'number': (value) => {
            const num = Number(value);
            return isNaN(num) ? value : num.toLocaleString();
        },
        'percent': (value) => {
            const num = Number(value);
            return isNaN(num) ? value : `${(num * 100).toFixed(2)}%`;
        },
        'date': (value) => {
            try {
                return new Date(value).toLocaleDateString();
            } catch (e) {
                return value;
            }
        },
        'datetime': (value) => {
            try {
                return new Date(value).toLocaleString();
            } catch (e) {
                return value;
            }
        },
        'currency': (value) => {
            const num = Number(value);
            return isNaN(num) ? value : `$${num.toFixed(2)}`;
        }
    },

    /**
     * Main rendering function for execute array
     * 
     * @param {string} containerId - ID of the container element
     * @param {Array} executeArray - Array of execute items to render
     * @param {Object} options - Configuration options
     * @returns {Promise<void>}
     */
    renderExecute: async function(containerId, executeArray, options = {}) {
        const config = Object.assign({}, this.defaults, options);
        
        const container = document.getElementById(containerId);
        if (!container) {
            const error = new Error(`Container element '${containerId}' not found`);
            if (config.onError) {
                config.onError(error, {
                    phase: 'initialization',
                    containerId: containerId
                });
            } else {
                console.error(error);
                this._showErrorMessage(containerId, 'Failed to initialize conversation UI');
            }
            throw error;
        }

        if (!Array.isArray(executeArray)) {
            const error = new Error('executeArray must be an array');
            if (config.onError) {
                config.onError(error, {
                    phase: 'validation',
                    executeArray: executeArray
                });
            } else {
                console.error(error);
            }
            throw error;
        }

        const maxItems = config.maxExecuteItems || this.defaults.maxExecuteItems;
        if (executeArray.length > maxItems) {
            const error = new Error(`Execute array exceeds maximum length (${maxItems})`);
            console.warn(error.message);
            if (config.onError) {
                config.onError(error, {
                    phase: 'validation',
                    arrayLength: executeArray.length,
                    maxItems: maxItems
                });
            }
            executeArray = executeArray.slice(0, maxItems);
        }

        if (config.onBeforeRender && typeof config.onBeforeRender === 'function') {
            try {
                config.onBeforeRender(executeArray);
            } catch (error) {
                console.error('Error in onBeforeRender callback:', error);
            }
        }

        try {
            for (let i = 0; i < executeArray.length; i++) {
                const item = executeArray[i];
                
                if (!item || typeof item !== 'object') {
                    console.warn(`Invalid execute item at index ${i}:`, item);
                    continue;
                }

                const itemKey = Object.keys(item)[0];
                const value = item[itemKey];

                switch (itemKey) {
                    case 'msg':
                        await this._displayText(value, container, false, config);
                        break;
                    
                    case 'cmd':
                        await this._renderCommands(value, container, config);
                        break;
                    
                    case 'data':
                        await this._renderData(value, container, config);
                        break;
                    
                    case 'next':
                        if (config.onNext && typeof config.onNext === 'function') {
                            config.onNext(value);
                        }
                        break;
                    
                    default:
                        console.warn(`Unknown execute item type: ${itemKey}`);
                }

                const delay = config.itemDelay !== undefined ? config.itemDelay : this.defaults.itemDelay;
                if (delay > 0 && i < executeArray.length - 1) {
                    await new Promise(resolve => setTimeout(resolve, delay));
                }
            }

            if (config.onAfterRender && typeof config.onAfterRender === 'function') {
                try {
                    config.onAfterRender();
                } catch (error) {
                    console.error('Error in onAfterRender callback:', error);
                }
            }
        } catch (error) {
            if (config.onError) {
                config.onError(error, {
                    phase: 'execution',
                    containerId: containerId
                });
            } else {
                console.error('Error during execute processing:', error);
                this._showErrorMessage(containerId, 'An error occurred while processing the conversation');
            }
            throw error;
        }
    },

    /**
     * Display text with optional typewriter effect
     * 
     * @param {string} html - HTML content to display
     * @param {HTMLElement} container - Container element
     * @param {boolean} isUser - Whether this is a user message
     * @param {Object} config - Configuration options
     * @returns {Promise<HTMLElement>}
     * @private
     */
    _displayText: async function(html, container, isUser, config) {
        return new Promise((resolve, reject) => {
            try {
                if (!container) {
                    const error = new Error('Container element is null or undefined');
                    if (config.onError) {
                        config.onError(error, {
                            phase: 'displayText',
                            isUser: isUser
                        });
                    } else {
                        console.error(error);
                    }
                    reject(error);
                    return;
                }

                if (html === null || html === undefined) {
                    console.warn('HTML content is null or undefined, skipping display');
                    resolve();
                    return;
                }

                const htmlString = String(html);

                const enableTypewriter = config.enableTypewriter !== undefined 
                    ? config.enableTypewriter 
                    : this.defaults.enableTypewriter;

                const autoScroll = config.autoScroll !== undefined ? config.autoScroll : true;

                if (!enableTypewriter) {
                    const messageDiv = document.createElement('div');
                    messageDiv.classList.add('qahm-conversation-message');
                    if (isUser) {
                        messageDiv.classList.add('qahm-conversation-user');
                    }
                    messageDiv.innerHTML = htmlString;
                    container.appendChild(messageDiv);
                    if (autoScroll) {
                        container.scrollTop = container.scrollHeight;
                    }
                    
                    if (config.onMessageRendered && typeof config.onMessageRendered === 'function') {
                        try {
                            config.onMessageRendered(messageDiv, 'msg');
                        } catch (error) {
                            console.error('Error in onMessageRendered callback:', error);
                        }
                    }
                    
                    resolve(messageDiv);
                    return;
                }

                const parser = new DOMParser();
                const doc = parser.parseFromString(htmlString, 'text/html');
                const nodes = Array.from(doc.body.childNodes);
                
                let charIndex = 0;
                let nodeIndex = 0;
                let currentParagraph = null;

                const typewriterSpeed = config.typewriterSpeed !== undefined 
                    ? config.typewriterSpeed 
                    : this.defaults.typewriterSpeed;

                const intervalId = setInterval(() => {
                    try {
                        if (nodeIndex >= nodes.length) {
                            clearInterval(intervalId);
                            
                            if (config.onMessageRendered && typeof config.onMessageRendered === 'function') {
                                try {
                                    config.onMessageRendered(currentParagraph, 'msg');
                                } catch (error) {
                                    console.error('Error in onMessageRendered callback:', error);
                                }
                            }
                            
                            resolve(currentParagraph);
                            return;
                        }

                        const node = nodes[nodeIndex];

                        if (node.nodeType === Node.TEXT_NODE) {
                            if (!currentParagraph) {
                                currentParagraph = document.createElement('div');
                                currentParagraph.classList.add('qahm-conversation-message');
                                if (isUser) {
                                    currentParagraph.classList.add('qahm-conversation-user');
                                }
                                container.appendChild(currentParagraph);
                            }

                            const textContent = node.textContent || '';
                            if (charIndex < textContent.length) {
                                const contentToAdd = textContent[charIndex];
                                currentParagraph.innerHTML += contentToAdd;
                                charIndex++;
                            }

                            if (charIndex >= textContent.length) {
                                charIndex = 0;
                                nodeIndex++;
                            }
                        } 
                        else if (node.nodeType === Node.ELEMENT_NODE) {
                            if (!currentParagraph) {
                                currentParagraph = document.createElement('div');
                                currentParagraph.classList.add('qahm-conversation-message');
                                if (isUser) {
                                    currentParagraph.classList.add('qahm-conversation-user');
                                }
                                container.appendChild(currentParagraph);
                            }
                            currentParagraph.appendChild(node.cloneNode(true));
                            nodeIndex++;
                        }
                        else {
                            nodeIndex++;
                        }

                        if (autoScroll) {
                            container.scrollTop = container.scrollHeight;
                        }

                    } catch (error) {
                        clearInterval(intervalId);
                        console.error('Error in typewriter animation:', error);
                        if (config.onError) {
                            config.onError(error, {
                                phase: 'typewriter',
                                nodeIndex: nodeIndex
                            });
                        }
                        reject(error);
                    }
                }, typewriterSpeed);

            } catch (error) {
                if (config.onError) {
                    config.onError(error, {
                        phase: 'displayText',
                        isUser: isUser
                    });
                } else {
                    console.error('Error in _displayText:', error);
                }
                reject(error);
            }
        });
    },

    /**
     * Render command buttons with proper event handling and cleanup
     * 
     * @param {Array|Object} commands - Command definitions
     * @param {HTMLElement} container - Container element
     * @param {Object} config - Configuration options
     * @returns {Promise<void>}
     * @private
     */
    _renderCommands: async function(commands, container, config) {
        return new Promise((resolve) => {
            try {
                if (!container) {
                    const error = new Error('Container element is null');
                    if (config.onError) {
                        config.onError(error, {phase: 'renderCommands'});
                    }
                    throw error;
                }

                if (!commands || (typeof commands !== 'object')) {
                    console.warn('Invalid commands structure:', commands);
                    resolve();
                    return;
                }

                const commandBox = document.createElement('div');
                commandBox.classList.add('qahm-conversation-command-box');

                const listeners = [];

                const translations = config.translations || {};

                const commandArray = Array.isArray(commands) ? commands : Object.values(commands);
                
                commandArray.forEach((cmd, index) => {
                    if (!cmd || typeof cmd !== 'object') {
                        console.warn(`Invalid command at index ${index}:`, cmd);
                        return;
                    }

                    const button = document.createElement('button');
                    button.classList.add('qahm-conversation-command-button');
                    button.textContent = cmd.text || `Command ${index + 1}`;

                    const endCommandLabel = translations.endCommandLabel || 'End';
                    if (button.textContent === endCommandLabel) {
                        button.classList.add('qahm-conversation-command-button-end');
                    }

                    if (cmd.action) {
                        const clickHandler = () => {
                            this._cleanup(commandBox);

                            if (config.onCommandClick) {
                                const actionWithText = Object.assign({}, cmd.action, {
                                    userMessage: cmd.text || `Command ${index + 1}`
                                });
                                config.onCommandClick(actionWithText);
                            }

                            if (cmd.action.link) {
                                window.location.href = cmd.action.link;
                            }

                            if (cmd.action.close === 'window') {
                                window.close();
                            }

                            resolve();
                        };

                        button.addEventListener('click', clickHandler);
                        listeners.push({
                            element: button,
                            event: 'click',
                            handler: clickHandler
                        });
                    }

                    commandBox.appendChild(button);
                });

                commandBox._listeners = listeners;

                container.appendChild(commandBox);
                if (config.autoScroll === undefined || config.autoScroll) {
                    container.scrollTop = container.scrollHeight;
                }

                if (config.onMessageRendered && typeof config.onMessageRendered === 'function') {
                    try {
                        config.onMessageRendered(commandBox, 'cmd');
                    } catch (error) {
                        console.error('Error in onMessageRendered callback:', error);
                    }
                }

                if (listeners.length === 0) {
                    resolve();
                }

            } catch (error) {
                if (config.onError) {
                    config.onError(error, {phase: 'renderCommands'});
                } else {
                    console.error('Error in _renderCommands:', error);
                }
                resolve(); // Don't block execution
            }
        });
    },

    /**
     * Render data table with safe formatter handling (no eval)
     * 
     * @param {Object} data - Table data with header, body, and options
     * @param {HTMLElement} container - Container element
     * @param {Object} config - Configuration options
     * @returns {Promise<void>}
     * @private
     */
    _renderData: async function(data, container, config) {
        return new Promise((resolve) => {
            try {
                if (!container) {
                    const error = new Error('Container element is null');
                    if (config.onError) {
                        config.onError(error, {phase: 'renderData'});
                    }
                    throw error;
                }

                if (!data || typeof data !== 'object') {
                    console.warn('Invalid data structure:', data);
                    resolve();
                    return;
                }

                const processedHeaders = Array.isArray(data.header) ? data.header.map(h => {
                    const headerCopy = Object.assign({}, h);
                    
                    if (headerCopy.formatter) {
                        if (typeof headerCopy.formatter === 'string') {
                            const safeFormatter = this.SAFE_FORMATTERS[headerCopy.formatter];
                            if (safeFormatter) {
                                headerCopy.formatter = safeFormatter;
                            } else {
                                console.warn(`Unknown formatter: ${headerCopy.formatter}. Ignoring.`);
                                headerCopy.formatter = null;
                            }
                        } else if (typeof headerCopy.formatter === 'function') {
                        } else {
                            console.warn('Invalid formatter type:', typeof headerCopy.formatter);
                            headerCopy.formatter = null;
                        }
                    }
                    
                    return headerCopy;
                }) : [];

                const processedData = {
                    header: processedHeaders,
                    body: data.body || [],
                    option: data.option || {},
                    title: data.title || ''
                };

                const dataContainer = document.createElement('div');
                dataContainer.classList.add('qahm-conversation-data-wrapper');
                
                if (config.tableRenderer && typeof config.tableRenderer === 'function') {
                    config.tableRenderer(processedData, dataContainer);
                } else {
                    this._renderBasicTable(processedData, dataContainer, config);
                }

                container.appendChild(dataContainer);

                if (config.onMessageRendered && typeof config.onMessageRendered === 'function') {
                    try {
                        config.onMessageRendered(dataContainer, 'data');
                    } catch (error) {
                        console.error('Error in onMessageRendered callback:', error);
                    }
                }

                resolve();

            } catch (error) {
                if (config.onError) {
                    config.onError(error, {phase: 'renderData'});
                } else {
                    console.error('Error in _renderData:', error);
                }
                resolve(); // Don't block execution
            }
        });
    },

    /**
     * Safely render cell content with allowed HTML tags (whitelist approach)
     * 
     * @param {string} content - Cell content
     * @returns {string} Sanitized HTML
     * @private
     */
    _sanitizeTableCell: function(content) {
        if (content === null || content === undefined) {
            return '';
        }
        
        const contentStr = String(content);
        
        const allowedTags = ['span'];
        
        if (!contentStr.includes('<')) {
            return contentStr;
        }
        
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = contentStr;
        
        const elements = tempDiv.querySelectorAll('*');
        elements.forEach(el => {
            const tagName = el.tagName.toLowerCase();
            
            if (!allowedTags.includes(tagName)) {
                el.replaceWith(el.textContent);
                return;
            }
            
            if (tagName === 'span') {
                const dangerousAttrs = ['onclick', 'onload', 'onerror', 'onmouseover', 'onmouseout', 'onfocus', 'onblur'];
                dangerousAttrs.forEach(attr => {
                    if (el.hasAttribute(attr)) {
                        el.removeAttribute(attr);
                    }
                });
                
                if (el.hasAttribute('style')) {
                    el.removeAttribute('style');
                }
            }
        });
        
        return tempDiv.innerHTML;
    },

    /**
     * Basic HTML table renderer (fallback when no custom renderer provided)
     * 
     * @param {Object} data - Processed table data
     * @param {HTMLElement} container - Container element
     * @param {Object} [config] - Configuration options
     * @private
     */
    _renderBasicTable: function(data, container, config) {
        config = config || {};
        const tableContainer = document.createElement('div');
        tableContainer.classList.add('qahm-conversation-data-container');

        if (data.title) {
            const titleDiv = document.createElement('div');
            titleDiv.classList.add('qahm-conversation-data-title');
            titleDiv.textContent = data.title;
            tableContainer.appendChild(titleDiv);
        }

        const table = document.createElement('table');
        table.classList.add('qahm-conversation-data-table');

        if (data.header && data.header.length > 0) {
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            
            data.header.forEach(h => {
                const th = document.createElement('th');
                th.textContent = h.label || h.data || '';
                headerRow.appendChild(th);
            });
            
            thead.appendChild(headerRow);
            table.appendChild(thead);
        }

        if (data.body && data.body.length > 0) {
            const tbody = document.createElement('tbody');
            
            data.body.forEach(row => {
                const tr = document.createElement('tr');
                
                data.header.forEach(h => {
                    const td = document.createElement('td');
                    const dataKey = h.data;
                    let cellValue = row[dataKey];

                    if (h.formatter && typeof h.formatter === 'function') {
                        try {
                            cellValue = h.formatter(cellValue);
                        } catch (e) {
                            console.warn('Formatter error:', e);
                        }
                    }

                    td.innerHTML = this._sanitizeTableCell(cellValue);
                    tr.appendChild(td);
                });
                
                tbody.appendChild(tr);
            });
            
            table.appendChild(tbody);
        }

        tableContainer.appendChild(table);
        container.appendChild(tableContainer);
        if (config.autoScroll === undefined || config.autoScroll) {
            container.scrollTop = container.scrollHeight;
        }
    },

    /**
     * Cleanup function to remove event listeners and prevent memory leaks
     * 
     * @param {HTMLElement} element - Element to cleanup
     * @private
     */
    _cleanup: function(element) {
        if (!element) {
            return;
        }

        try {
            if (element._listeners && Array.isArray(element._listeners)) {
                element._listeners.forEach(({element: el, event, handler}) => {
                    if (el && event && handler) {
                        el.removeEventListener(event, handler);
                    }
                });
                delete element._listeners;
            }

            if (element.parentNode) {
                element.parentNode.removeChild(element);
            }
        } catch (error) {
            console.error('Error during cleanup:', error);
        }
    },

    /**
     * Show error message in container (fallback when onError not provided)
     * 
     * @param {string} containerId - Container element ID
     * @param {string} message - Error message to display
     * @private
     */
    _showErrorMessage: function(containerId, message) {
        try {
            let container = document.getElementById(containerId);
            if (!container) {
                container = document.body;
            }

            const errorDiv = document.createElement('div');
            errorDiv.classList.add('qahm-conversation-error');
            errorDiv.textContent = message || 'An error occurred';
            errorDiv.style.color = '#d32f2f';
            errorDiv.style.padding = '10px';
            errorDiv.style.margin = '10px 0';
            errorDiv.style.border = '1px solid #d32f2f';
            errorDiv.style.borderRadius = '4px';
            errorDiv.style.backgroundColor = '#ffebee';

            container.appendChild(errorDiv);
        } catch (e) {
            console.error('Failed to show error message:', e);
        }
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = qahm.conversationUI;
}
