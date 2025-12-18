// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Client Cache for offline response storage
 *
 * Provides IndexedDB-based storage for pending quiz responses during network
 * interruptions. Automatically retries submission when connectivity is restored.
 *
 * Requirements: 4.1, 4.2, 4.3, 4.5
 *
 * @module     mod_classengage/client_cache
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    /**
     * Database configuration
     * @type {Object}
     */
    var DB_CONFIG = {
        name: 'classengage_cache',
        version: 1,
        storeName: 'pending_responses'
    };

    /**
     * Default configuration options
     * @type {Object}
     */
    var DEFAULTS = {
        maxRetries: 5,
        retryDelay: 1000,
        maxCacheAge: 3600000 // 1 hour in milliseconds
    };

    /**
     * Client Cache constructor
     * @constructor
     */
    function ClientCache() {
        this.db = null;
        this.options = $.extend({}, DEFAULTS);
        this.isInitialized = false;
        this.connectionManager = null;

        // Statistics tracking
        this.stats = {
            stored: 0,
            submitted: 0,
            failed: 0,
            pending: 0
        };

        // Event handlers
        this.eventHandlers = {};
    }

    /**
     * Initialize the client cache
     *
     * @param {Object} options Configuration options
     * @return {Promise} Resolves when initialized
     */
    ClientCache.prototype.init = function(options) {
        var self = this;

        this.options = $.extend({}, DEFAULTS, options || {});

        // eslint-disable-next-line no-unused-vars
        return new Promise(function(resolve, reject) {
            // Check IndexedDB support
            if (!self.isIndexedDBSupported()) {
                // Fallback to in-memory storage
                self.useMemoryFallback();
                self.isInitialized = true;
                resolve();
                return;
            }

            var request = indexedDB.open(DB_CONFIG.name, DB_CONFIG.version);

            request.onerror = function(event) {
                // Fallback to in-memory storage on error
                // eslint-disable-next-line no-console
                console.warn('IndexedDB error, using memory fallback:', event);
                self.useMemoryFallback();
                self.isInitialized = true;
                resolve();
            };

            request.onsuccess = function(event) {
                self.db = event.target.result;
                self.isInitialized = true;
                self.updatePendingCount();
                resolve();
            };

            request.onupgradeneeded = function(event) {
                var db = event.target.result;

                // Create object store for pending responses
                if (!db.objectStoreNames.contains(DB_CONFIG.storeName)) {
                    var store = db.createObjectStore(DB_CONFIG.storeName, {keyPath: 'id'});
                    store.createIndex('sessionId', 'sessionId', {unique: false});
                    store.createIndex('timestamp', 'timestamp', {unique: false});
                    store.createIndex('status', 'status', {unique: false});
                }
            };
        });
    };

    /**
     * Check if IndexedDB is supported
     *
     * @return {boolean} True if supported
     */
    ClientCache.prototype.isIndexedDBSupported = function() {
        return typeof indexedDB !== 'undefined';
    };

    /**
     * Use in-memory fallback when IndexedDB is not available
     *
     * @private
     */
    ClientCache.prototype.useMemoryFallback = function() {
        this.memoryStore = [];
        this.db = null;
    };

    /**
     * Generate a unique ID for a response
     *
     * @return {string} Unique ID
     * @private
     */
    ClientCache.prototype.generateId = function() {
        return 'resp_' + Date.now() + '_' + Math.random().toString(36).substring(2, 11);
    };

    /**
     * Store a pending response
     *
     * @param {Object} response Response data
     * @return {Promise} Resolves with stored response
     */
    ClientCache.prototype.storeResponse = function(response) {
        var self = this;

        var pendingResponse = {
            id: this.generateId(),
            sessionId: response.sessionId,
            questionId: response.questionId,
            answer: response.answer,
            timestamp: Date.now(),
            clientTimestamp: response.clientTimestamp || Date.now(),
            retryCount: 0,
            status: 'pending',
            lastError: null
        };

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                self.memoryStore.push(pendingResponse);
                self.stats.stored++;
                self.stats.pending++;
                self.emit('stored', pendingResponse);
                resolve(pendingResponse);
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readwrite');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var request = store.add(pendingResponse);

            request.onsuccess = function() {
                self.stats.stored++;
                self.stats.pending++;
                self.emit('stored', pendingResponse);
                resolve(pendingResponse);
            };

            request.onerror = function() {
                reject(new Error('Failed to store response'));
            };
        });
    };

    /**
     * Get all pending responses
     *
     * @return {Promise<Array>} Resolves with array of pending responses
     */
    ClientCache.prototype.getPendingResponses = function() {
        var self = this;

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                var pending = self.memoryStore.filter(function(r) {
                    return r.status === 'pending';
                });
                resolve(pending);
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readonly');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var index = store.index('status');
            var request = index.getAll('pending');

            request.onsuccess = function(event) {
                resolve(event.target.result || []);
            };

            request.onerror = function() {
                reject(new Error('Failed to get pending responses'));
            };
        });
    };

    /**
     * Get pending responses for a specific session
     *
     * @param {number} sessionId Session ID
     * @return {Promise<Array>} Resolves with array of pending responses
     */
    ClientCache.prototype.getPendingBySession = function(sessionId) {
        return this.getPendingResponses().then(function(responses) {
            return responses.filter(function(r) {
                return r.sessionId === sessionId;
            });
        });
    };

    /**
     * Mark a response as submitted
     *
     * @param {string} responseId Response ID
     * @return {Promise} Resolves when marked
     */
    ClientCache.prototype.markSubmitted = function(responseId) {
        var self = this;

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                var index = self.memoryStore.findIndex(function(r) {
                    return r.id === responseId;
                });
                if (index !== -1) {
                    self.memoryStore[index].status = 'submitted';
                    self.stats.submitted++;
                    self.stats.pending = Math.max(0, self.stats.pending - 1);
                    self.emit('submitted', {id: responseId});
                }
                resolve();
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readwrite');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var getRequest = store.get(responseId);

            getRequest.onsuccess = function(event) {
                var response = event.target.result;
                if (response) {
                    response.status = 'submitted';
                    var updateRequest = store.put(response);
                    updateRequest.onsuccess = function() {
                        self.stats.submitted++;
                        self.stats.pending = Math.max(0, self.stats.pending - 1);
                        self.emit('submitted', {id: responseId});
                        resolve();
                    };
                    updateRequest.onerror = function() {
                        reject(new Error('Failed to update response'));
                    };
                } else {
                    resolve();
                }
            };

            getRequest.onerror = function() {
                reject(new Error('Failed to get response'));
            };
        });
    };

    /**
     * Mark a response as failed
     *
     * @param {string} responseId Response ID
     * @param {string} errorMsg Error message
     * @return {Promise} Resolves when marked
     */
    ClientCache.prototype.markFailed = function(responseId, errorMsg) {
        var self = this;

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                var index = self.memoryStore.findIndex(function(r) {
                    return r.id === responseId;
                });
                if (index !== -1) {
                    self.memoryStore[index].retryCount++;
                    self.memoryStore[index].lastError = errorMsg;
                    if (self.memoryStore[index].retryCount >= self.options.maxRetries) {
                        self.memoryStore[index].status = 'failed';
                        self.stats.failed++;
                        self.stats.pending = Math.max(0, self.stats.pending - 1);
                        self.emit('failed', {id: responseId, error: errorMsg});
                    }
                }
                resolve();
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readwrite');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var getRequest = store.get(responseId);

            getRequest.onsuccess = function(event) {
                var response = event.target.result;
                if (response) {
                    response.retryCount++;
                    response.lastError = errorMsg;
                    if (response.retryCount >= self.options.maxRetries) {
                        response.status = 'failed';
                        self.stats.failed++;
                        self.stats.pending = Math.max(0, self.stats.pending - 1);
                        self.emit('failed', {id: responseId, error: errorMsg});
                    }
                    var updateRequest = store.put(response);
                    updateRequest.onsuccess = function() {
                        resolve();
                    };
                    updateRequest.onerror = function() {
                        reject(new Error('Failed to update response'));
                    };
                } else {
                    resolve();
                }
            };

            getRequest.onerror = function() {
                reject(new Error('Failed to get response'));
            };
        });
    };

    /**
     * Remove a response from cache
     *
     * @param {string} responseId Response ID
     * @return {Promise} Resolves when removed
     */
    ClientCache.prototype.removeResponse = function(responseId) {
        var self = this;

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                var index = self.memoryStore.findIndex(function(r) {
                    return r.id === responseId;
                });
                if (index !== -1) {
                    var removed = self.memoryStore.splice(index, 1)[0];
                    if (removed.status === 'pending') {
                        self.stats.pending = Math.max(0, self.stats.pending - 1);
                    }
                }
                resolve();
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readwrite');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var request = store.delete(responseId);

            request.onsuccess = function() {
                self.updatePendingCount();
                resolve();
            };

            request.onerror = function() {
                reject(new Error('Failed to remove response'));
            };
        });
    };

    /**
     * Clear all pending responses
     *
     * @return {Promise} Resolves when cleared
     */
    ClientCache.prototype.clear = function() {
        var self = this;

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                self.memoryStore = [];
                self.stats.pending = 0;
                self.emit('cleared', {});
                resolve();
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readwrite');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var request = store.clear();

            request.onsuccess = function() {
                self.stats.pending = 0;
                self.emit('cleared', {});
                resolve();
            };

            request.onerror = function() {
                reject(new Error('Failed to clear cache'));
            };
        });
    };

    /**
     * Clean up old cached responses
     *
     * @return {Promise} Resolves when cleanup complete
     */
    ClientCache.prototype.cleanup = function() {
        var self = this;
        var cutoffTime = Date.now() - this.options.maxCacheAge;

        return new Promise(function(resolve, reject) {
            if (!self.isInitialized) {
                reject(new Error('Cache not initialized'));
                return;
            }

            if (self.memoryStore) {
                // Memory fallback
                self.memoryStore = self.memoryStore.filter(function(r) {
                    return r.timestamp > cutoffTime;
                });
                self.updatePendingCount();
                resolve();
                return;
            }

            var transaction = self.db.transaction([DB_CONFIG.storeName], 'readwrite');
            var store = transaction.objectStore(DB_CONFIG.storeName);
            var index = store.index('timestamp');
            var range = IDBKeyRange.upperBound(cutoffTime);
            var request = index.openCursor(range);

            request.onsuccess = function(event) {
                var cursor = event.target.result;
                if (cursor) {
                    cursor.delete();
                    cursor.continue();
                } else {
                    self.updatePendingCount();
                    resolve();
                }
            };

            request.onerror = function() {
                reject(new Error('Failed to cleanup cache'));
            };
        });
    };

    /**
     * Update pending count in stats
     *
     * @private
     */
    ClientCache.prototype.updatePendingCount = function() {
        var self = this;

        this.getPendingResponses().then(function(responses) {
            self.stats.pending = responses.length;
        }).catch(function() {
            // Ignore errors
        });
    };

    /**
     * Get cache statistics
     *
     * @return {Object} Cache statistics
     */
    ClientCache.prototype.getStats = function() {
        return $.extend({}, this.stats);
    };

    /**
     * Set connection manager for automatic retry
     *
     * @param {Object} connectionManager Connection manager instance
     */
    ClientCache.prototype.setConnectionManager = function(connectionManager) {
        var self = this;
        this.connectionManager = connectionManager;

        // Listen for reconnection events
        if (connectionManager && typeof connectionManager.on === 'function') {
            connectionManager.on('connected', function() {
                self.retryPendingResponses();
            });

            connectionManager.on('reconnected', function() {
                self.retryPendingResponses();
            });
        }
    };

    /**
     * Retry all pending responses
     *
     * @return {Promise} Resolves when all retries complete
     */
    ClientCache.prototype.retryPendingResponses = function() {
        var self = this;

        return this.getPendingResponses().then(function(responses) {
            if (responses.length === 0) {
                return Promise.resolve([]);
            }

            self.emit('retrying', {count: responses.length});

            var promises = responses.map(function(response) {
                return self.submitCachedResponse(response);
            });

            return Promise.all(promises);
        }).then(function(results) {
            self.emit('retryComplete', {results: results});
            return results;
        });
    };

    /**
     * Submit a cached response to the server
     *
     * @param {Object} cachedResponse Cached response object
     * @return {Promise} Resolves with submission result
     * @private
     */
    ClientCache.prototype.submitCachedResponse = function(cachedResponse) {
        var self = this;

        if (!this.connectionManager) {
            return Promise.reject(new Error('No connection manager'));
        }

        return this.connectionManager.send('submitanswer', {
            sessionid: cachedResponse.sessionId,
            questionid: cachedResponse.questionId,
            answer: cachedResponse.answer,
            clienttimestamp: cachedResponse.clientTimestamp
        }).then(function(response) {
            if (response.success) {
                return self.markSubmitted(cachedResponse.id).then(function() {
                    return {
                        id: cachedResponse.id,
                        success: true,
                        islate: response.islate || false
                    };
                });
            } else {
                // Check if it's a permanent failure (duplicate, session ended, etc.)
                if (self.isPermanentFailure(response.error)) {
                    return self.removeResponse(cachedResponse.id).then(function() {
                        return {
                            id: cachedResponse.id,
                            success: false,
                            error: response.error,
                            permanent: true
                        };
                    });
                }
                return self.markFailed(cachedResponse.id, response.error).then(function() {
                    return {
                        id: cachedResponse.id,
                        success: false,
                        error: response.error
                    };
                });
            }
        }).catch(function(error) {
            return self.markFailed(cachedResponse.id, error.message).then(function() {
                return {
                    id: cachedResponse.id,
                    success: false,
                    error: error.message
                };
            });
        });
    };

    /**
     * Check if an error is a permanent failure
     *
     * @param {string} error Error message
     * @return {boolean} True if permanent failure
     * @private
     */
    ClientCache.prototype.isPermanentFailure = function(error) {
        if (!error) {
            return false;
        }
        var permanentErrors = [
            'Duplicate submission',
            'Session not found',
            'Session not active',
            'Question not found',
            'already answered'
        ];
        return permanentErrors.some(function(msg) {
            return error.indexOf(msg) !== -1;
        });
    };

    /**
     * Check if there are pending responses
     *
     * @return {boolean} True if there are pending responses
     */
    ClientCache.prototype.hasPending = function() {
        return this.stats.pending > 0;
    };

    /**
     * Register event handler
     *
     * @param {string} event Event name
     * @param {Function} callback Callback function
     */
    ClientCache.prototype.on = function(event, callback) {
        if (!this.eventHandlers[event]) {
            this.eventHandlers[event] = [];
        }
        this.eventHandlers[event].push(callback);
    };

    /**
     * Remove event handler
     *
     * @param {string} event Event name
     * @param {Function} callback Callback function to remove
     */
    ClientCache.prototype.off = function(event, callback) {
        if (!this.eventHandlers[event]) {
            return;
        }

        if (callback) {
            this.eventHandlers[event] = this.eventHandlers[event].filter(function(cb) {
                return cb !== callback;
            });
        } else {
            delete this.eventHandlers[event];
        }
    };

    /**
     * Emit event to handlers
     *
     * @param {string} event Event name
     * @param {Object} data Event data
     * @private
     */
    ClientCache.prototype.emit = function(event, data) {
        var handlers = this.eventHandlers[event];
        if (handlers) {
            handlers.forEach(function(callback) {
                try {
                    callback(data);
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('Error in event handler for ' + event + ':', e);
                }
            });
        }
    };

    // Singleton instance
    var instance = null;

    return {
        /**
         * Get or create ClientCache instance
         *
         * @return {ClientCache} Client cache instance
         */
        getInstance: function() {
            if (!instance) {
                instance = new ClientCache();
            }
            return instance;
        },

        /**
         * Initialize client cache
         *
         * @param {Object} options Configuration options
         * @return {Promise} Resolves when initialized
         */
        init: function(options) {
            return this.getInstance().init(options);
        },

        /**
         * Store a pending response
         *
         * @param {Object} response Response data
         * @return {Promise} Resolves with stored response
         */
        storeResponse: function(response) {
            return this.getInstance().storeResponse(response);
        },

        /**
         * Get all pending responses
         *
         * @return {Promise<Array>} Resolves with array of pending responses
         */
        getPendingResponses: function() {
            return this.getInstance().getPendingResponses();
        },

        /**
         * Get pending responses for a session
         *
         * @param {number} sessionId Session ID
         * @return {Promise<Array>} Resolves with array of pending responses
         */
        getPendingBySession: function(sessionId) {
            return this.getInstance().getPendingBySession(sessionId);
        },

        /**
         * Mark a response as submitted
         *
         * @param {string} responseId Response ID
         * @return {Promise} Resolves when marked
         */
        markSubmitted: function(responseId) {
            return this.getInstance().markSubmitted(responseId);
        },

        /**
         * Mark a response as failed
         *
         * @param {string} responseId Response ID
         * @param {string} error Error message
         * @return {Promise} Resolves when marked
         */
        markFailed: function(responseId, error) {
            return this.getInstance().markFailed(responseId, error);
        },

        /**
         * Remove a response from cache
         *
         * @param {string} responseId Response ID
         * @return {Promise} Resolves when removed
         */
        removeResponse: function(responseId) {
            return this.getInstance().removeResponse(responseId);
        },

        /**
         * Clear all pending responses
         *
         * @return {Promise} Resolves when cleared
         */
        clear: function() {
            return this.getInstance().clear();
        },

        /**
         * Clean up old cached responses
         *
         * @return {Promise} Resolves when cleanup complete
         */
        cleanup: function() {
            return this.getInstance().cleanup();
        },

        /**
         * Get cache statistics
         *
         * @return {Object} Cache statistics
         */
        getStats: function() {
            return this.getInstance().getStats();
        },

        /**
         * Set connection manager for automatic retry
         *
         * @param {Object} connectionManager Connection manager instance
         */
        setConnectionManager: function(connectionManager) {
            this.getInstance().setConnectionManager(connectionManager);
        },

        /**
         * Retry all pending responses
         *
         * @return {Promise} Resolves when all retries complete
         */
        retryPendingResponses: function() {
            return this.getInstance().retryPendingResponses();
        },

        /**
         * Check if there are pending responses
         *
         * @return {boolean} True if there are pending responses
         */
        hasPending: function() {
            return this.getInstance().hasPending();
        },

        /**
         * Register event handler
         *
         * @param {string} event Event name
         * @param {Function} callback Callback function
         */
        on: function(event, callback) {
            this.getInstance().on(event, callback);
        },

        /**
         * Remove event handler
         *
         * @param {string} event Event name
         * @param {Function} callback Callback function
         */
        off: function(event, callback) {
            this.getInstance().off(event, callback);
        }
    };
});
