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
 * Connection Manager for real-time quiz communication
 *
 * Provides dual-transport support with SSE (Server-Sent Events) as primary
 * and HTTP polling as fallback. Handles automatic reconnection, heartbeat
 * management, and transport switching.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5
 *
 * @module     mod_classengage/connection_manager
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    /**
     * Connection status constants
     * @type {Object}
     */
    var STATUS = {
        DISCONNECTED: 'disconnected',
        CONNECTING: 'connecting',
        CONNECTED: 'connected',
        RECONNECTING: 'reconnecting'
    };

    /**
     * Transport type constants
     * @type {Object}
     */
    var TRANSPORT = {
        SSE: 'sse',
        POLLING: 'polling',
        OFFLINE: 'offline'
    };

    /**
     * Default configuration options
     * @type {Object}
     */
    var DEFAULTS = {
        sseEndpoint: '/mod/classengage/sse_handler.php',
        pollEndpoint: '/mod/classengage/ajax.php',
        pollInterval: 2000, // 2 seconds max (Requirement 6.2)
        sseRetryAttempts: 3, // 3 attempts before fallback (Requirement 6.3)
        heartbeatInterval: 30000, // 30 seconds
        reconnectDelay: 1000, // Initial reconnect delay
        maxReconnectDelay: 30000, // Max reconnect delay
        connectionTimeout: 10000 // Connection timeout
    };

    /**
     * Connection Manager constructor
     * @constructor
     */
    function ConnectionManager() {
        this.sessionId = null;
        this.connectionId = null;
        this.options = $.extend({}, DEFAULTS);

        // State tracking
        this.status = STATUS.DISCONNECTED;
        this.transport = TRANSPORT.OFFLINE;
        this.latency = 0;
        this.lastHeartbeat = 0;

        // SSE connection
        this.eventSource = null;
        this.sseAttempts = 0;

        // Polling
        this.pollingTimer = null;
        this.lastEventId = 0;

        // Heartbeat
        this.heartbeatTimer = null;

        // Reconnection
        this.reconnectTimer = null;
        this.reconnectDelay = DEFAULTS.reconnectDelay;

        // Event handlers
        this.eventHandlers = {};

        // Request tracking for latency calculation
        this.pendingRequests = {};
    }

    /**
     * Initialize connection with session
     *
     * @param {number} sessionId Session ID to connect to
     * @param {Object} options Configuration options
     * @return {Promise} Resolves when connected
     */
    ConnectionManager.prototype.init = function(sessionId, options) {
        var self = this;

        this.sessionId = sessionId;
        this.options = $.extend({}, DEFAULTS, options || {});
        this.connectionId = this.generateConnectionId();

        self.status = STATUS.CONNECTING;
        self.emit('statuschange', {status: self.status});

        // Try SSE first (Requirement 6.3)
        return self.connectSSE()
            .catch(function() {
                // SSE failed, fall back to polling (Requirement 6.1)
                return self.startPolling();
            });
    };

    /**
     * Generate a unique connection ID
     *
     * @return {string} Connection ID
     * @private
     */
    ConnectionManager.prototype.generateConnectionId = function() {
        return 'conn_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    };

    /**
     * Connect using Server-Sent Events
     *
     * @return {Promise} Resolves when SSE connected
     * @private
     */
    ConnectionManager.prototype.connectSSE = function() {
        var self = this;

        return new Promise(function(resolve, reject) {
            // Check if SSE is supported
            if (typeof EventSource === 'undefined') {
                self.sseAttempts = self.options.sseRetryAttempts;
                reject(new Error('SSE not supported'));
                return;
            }

            self.sseAttempts++;

            var url = M.cfg.wwwroot + self.options.sseEndpoint +
                '?sessionid=' + self.sessionId +
                '&connectionid=' + encodeURIComponent(self.connectionId) +
                '&lastEventId=' + self.lastEventId;

            try {
                self.eventSource = new EventSource(url);
            } catch (e) {
                if (self.sseAttempts < self.options.sseRetryAttempts) {
                    setTimeout(function() {
                        self.connectSSE().then(resolve).catch(reject);
                    }, 1000);
                } else {
                    reject(new Error('SSE connection failed'));
                }
                return;
            }

            var connectionTimeout = setTimeout(function() {
                if (self.status !== STATUS.CONNECTED) {
                    self.closeSSE();
                    if (self.sseAttempts < self.options.sseRetryAttempts) {
                        self.connectSSE().then(resolve).catch(reject);
                    } else {
                        reject(new Error('SSE connection timeout'));
                    }
                }
            }, self.options.connectionTimeout);

            self.eventSource.onopen = function() {
                // Connection opened, wait for 'connected' event
            };

            self.eventSource.onerror = function() {
                clearTimeout(connectionTimeout);
                self.closeSSE();

                if (self.sseAttempts < self.options.sseRetryAttempts) {
                    setTimeout(function() {
                        self.connectSSE().then(resolve).catch(reject);
                    }, 1000);
                } else {
                    reject(new Error('SSE connection failed after ' + self.sseAttempts + ' attempts'));
                }
            };

            // Handle connected event
            self.eventSource.addEventListener('connected', function(event) {
                clearTimeout(connectionTimeout);
                var data = JSON.parse(event.data);
                self.connectionId = data.connectionid;
                self.status = STATUS.CONNECTED;
                self.transport = TRANSPORT.SSE;
                self.sseAttempts = 0;
                self.reconnectDelay = DEFAULTS.reconnectDelay;
                self.lastEventId = parseInt(event.lastEventId) || 0;

                self.startHeartbeat();
                self.emit('statuschange', {status: self.status, transport: self.transport});
                self.emit('connected', data);
                resolve();
            });

            // Register SSE event handlers
            self.registerSSEHandlers();
        });
    };

    /**
     * Register handlers for SSE events
     *
     * @private
     */
    ConnectionManager.prototype.registerSSEHandlers = function() {
        var self = this;

        if (!this.eventSource) {
            return;
        }

        var events = [
            'session_started',
            'session_paused',
            'session_resumed',
            'session_completed',
            'session_ended',
            'question_broadcast',
            'timer_sync',
            'reconnect',
            'error'
        ];

        events.forEach(function(eventType) {
            self.eventSource.addEventListener(eventType, function(event) {
                self.lastEventId = parseInt(event.lastEventId) || self.lastEventId;
                var data = JSON.parse(event.data);
                self.emit(eventType, data);

                // Handle reconnect request from server
                if (eventType === 'reconnect') {
                    self.handleReconnectRequest(data);
                }

                // Handle session end
                if (eventType === 'session_completed' || eventType === 'session_ended') {
                    self.disconnect();
                }
            });
        });
    };

    /**
     * Close SSE connection
     *
     * @private
     */
    ConnectionManager.prototype.closeSSE = function() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    };

    /**
     * Start HTTP polling fallback
     *
     * @return {Promise} Resolves when polling started
     * @private
     */
    ConnectionManager.prototype.startPolling = function() {
        var self = this;

        return new Promise(function(resolve, reject) {
            // First, do a reconnect call to register connection and get state
            self.pollReconnect()
                .then(function(response) {
                    if (response.success) {
                        self.connectionId = response.connectionid;
                        self.status = STATUS.CONNECTED;
                        self.transport = TRANSPORT.POLLING;
                        self.reconnectDelay = DEFAULTS.reconnectDelay;

                        self.startHeartbeat();
                        self.startPollingLoop();

                        self.emit('statuschange', {status: self.status, transport: self.transport});
                        self.emit('connected', response);
                        resolve();
                    } else {
                        reject(new Error(response.error || 'Polling connection failed'));
                    }
                    return null;
                })
                .catch(function(error) {
                    reject(error);
                });
        });
    };

    /**
     * Start the polling loop
     *
     * @private
     */
    ConnectionManager.prototype.startPollingLoop = function() {
        var self = this;

        this.stopPolling();

        // Ensure polling interval doesn't exceed 2 seconds (Requirement 6.2)
        var interval = Math.min(this.options.pollInterval, 2000);

        this.pollingTimer = setInterval(function() {
            self.poll();
        }, interval);

        // Do initial poll
        this.poll();
    };

    /**
     * Perform a single poll request
     *
     * @private
     */
    ConnectionManager.prototype.poll = function() {
        var self = this;
        var startTime = Date.now();

        $.ajax({
            url: M.cfg.wwwroot + this.options.pollEndpoint,
            method: 'POST',
            data: {
                action: 'getstatus',
                sessionid: this.sessionId,
                connectionid: this.connectionId,
                sesskey: M.cfg.sesskey
            },
            dataType: 'json',
            timeout: this.options.connectionTimeout
        })
        .done(function(response) {
            self.latency = Date.now() - startTime;

            if (response.success) {
                // Emit session state update
                if (response.session) {
                    self.emit('state_update', response.session);

                    // Check for status changes
                    if (response.session.status === 'completed') {
                        self.emit('session_completed', response.session);
                        self.disconnect();
                    }
                }
            }
        })
        .fail(function() {
            self.handleConnectionError();
        });
    };

    /**
     * Poll for reconnection
     *
     * @return {Promise} Resolves with reconnect response
     * @private
     */
    ConnectionManager.prototype.pollReconnect = function() {
        var self = this;

        return new Promise(function(resolve, reject) {
            $.ajax({
                url: M.cfg.wwwroot + self.options.pollEndpoint,
                method: 'POST',
                data: {
                    action: 'reconnect',
                    sessionid: self.sessionId,
                    connectionid: self.connectionId,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                timeout: self.options.connectionTimeout
            })
            .done(function(response) {
                resolve(response);
            })
            .fail(function(xhr, status, error) {
                reject(new Error(error || 'Reconnect request failed'));
            });
        });
    };

    /**
     * Stop polling
     *
     * @private
     */
    ConnectionManager.prototype.stopPolling = function() {
        if (this.pollingTimer) {
            clearInterval(this.pollingTimer);
            this.pollingTimer = null;
        }
    };

    /**
     * Start heartbeat timer
     *
     * @private
     */
    ConnectionManager.prototype.startHeartbeat = function() {
        var self = this;

        this.stopHeartbeat();

        this.heartbeatTimer = setInterval(function() {
            self.sendHeartbeat();
        }, this.options.heartbeatInterval);
    };

    /**
     * Stop heartbeat timer
     *
     * @private
     */
    ConnectionManager.prototype.stopHeartbeat = function() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    };

    /**
     * Send heartbeat to server
     *
     * @private
     */
    ConnectionManager.prototype.sendHeartbeat = function() {
        var self = this;
        var startTime = Date.now();

        $.ajax({
            url: M.cfg.wwwroot + this.options.pollEndpoint,
            method: 'POST',
            data: {
                action: 'heartbeat',
                sessionid: this.sessionId,
                connectionid: this.connectionId,
                sesskey: M.cfg.sesskey
            },
            dataType: 'json',
            timeout: 5000
        })
        .done(function(response) {
            self.latency = Date.now() - startTime;
            self.lastHeartbeat = Date.now();

            if (response.success) {
                self.emit('heartbeat', {
                    latency: self.latency,
                    timestamp: response.servertimestamp
                });
            }
        })
        .fail(function() {
            // Heartbeat failed, but don't trigger reconnect immediately
            // The polling or SSE will handle connection issues
        });
    };

    /**
     * Handle connection error
     *
     * @private
     */
    ConnectionManager.prototype.handleConnectionError = function() {
        if (this.status === STATUS.DISCONNECTED) {
            return;
        }

        this.status = STATUS.RECONNECTING;
        this.emit('statuschange', {status: this.status});
        this.emit('disconnected', {reason: 'connection_error'});

        this.scheduleReconnect();
    };

    /**
     * Handle reconnect request from server
     *
     * @private
     */
    ConnectionManager.prototype.handleReconnectRequest = function() {
        this.closeSSE();
        this.stopPolling();
        this.scheduleReconnect();
    };

    /**
     * Schedule a reconnection attempt
     *
     * @private
     */
    ConnectionManager.prototype.scheduleReconnect = function() {
        var self = this;

        if (this.reconnectTimer) {
            return;
        }

        this.reconnectTimer = setTimeout(function() {
            self.reconnectTimer = null;
            self.reconnect();
        }, this.reconnectDelay);

        // Exponential backoff
        this.reconnectDelay = Math.min(
            this.reconnectDelay * 2,
            this.options.maxReconnectDelay
        );
    };

    /**
     * Force reconnection
     *
     * @return {Promise} Resolves when reconnected
     */
    ConnectionManager.prototype.reconnect = function() {
        var self = this;

        // Clear any pending reconnect
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }

        // Close existing connections
        this.closeSSE();
        this.stopPolling();
        this.stopHeartbeat();

        this.status = STATUS.RECONNECTING;
        this.emit('statuschange', {status: this.status});

        // Reset SSE attempts for fresh reconnection
        this.sseAttempts = 0;

        // Try SSE first, then polling
        return self.connectSSE()
            .then(function() {
                self.emit('reconnected', {transport: self.transport});
            })
            .catch(function() {
                return self.startPolling()
                    .then(function() {
                        self.emit('reconnected', {transport: self.transport});
                    })
                    .catch(function(error) {
                        self.status = STATUS.DISCONNECTED;
                        self.transport = TRANSPORT.OFFLINE;
                        self.emit('statuschange', {status: self.status, transport: self.transport});
                        throw error;
                    });
            });
    };

    /**
     * Send message to server
     *
     * @param {string} type Message type (action)
     * @param {Object} data Message data
     * @return {Promise} Resolves with server response
     */
    ConnectionManager.prototype.send = function(type, data) {
        var self = this;
        var startTime = Date.now();
        var requestId = this.generateConnectionId();

        this.pendingRequests[requestId] = startTime;

        var requestData = $.extend({
            action: type,
            sessionid: this.sessionId,
            connectionid: this.connectionId,
            sesskey: M.cfg.sesskey
        }, data || {});

        return new Promise(function(resolve, reject) {
            $.ajax({
                url: M.cfg.wwwroot + self.options.pollEndpoint,
                method: 'POST',
                data: requestData,
                dataType: 'json',
                timeout: self.options.connectionTimeout
            })
            .done(function(response) {
                delete self.pendingRequests[requestId];
                self.latency = Date.now() - startTime;
                resolve(response);
            })
            .fail(function(xhr, status, error) {
                delete self.pendingRequests[requestId];
                reject(new Error(error || 'Request failed'));
            });
        });
    };

    /**
     * Register event handler
     *
     * @param {string} event Event name
     * @param {Function} callback Callback function
     */
    ConnectionManager.prototype.on = function(event, callback) {
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
    ConnectionManager.prototype.off = function(event, callback) {
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
    ConnectionManager.prototype.emit = function(event, data) {
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

    /**
     * Get current connection status
     *
     * @return {Object} Connection status
     */
    ConnectionManager.prototype.getStatus = function() {
        return {
            connected: this.status === STATUS.CONNECTED,
            status: this.status,
            transport: this.transport,
            latency: this.latency,
            lastHeartbeat: this.lastHeartbeat,
            connectionId: this.connectionId
        };
    };

    /**
     * Check if currently connected
     *
     * @return {boolean} True if connected
     */
    ConnectionManager.prototype.isConnected = function() {
        return this.status === STATUS.CONNECTED;
    };

    /**
     * Get current transport type
     *
     * @return {string} Transport type
     */
    ConnectionManager.prototype.getTransport = function() {
        return this.transport;
    };

    /**
     * Graceful disconnect
     */
    ConnectionManager.prototype.disconnect = function() {
        this.closeSSE();
        this.stopPolling();
        this.stopHeartbeat();

        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }

        this.status = STATUS.DISCONNECTED;
        this.transport = TRANSPORT.OFFLINE;

        this.emit('statuschange', {status: this.status, transport: this.transport});
        this.emit('disconnected', {reason: 'user_disconnect'});
    };

    // Export constants for external use
    ConnectionManager.STATUS = STATUS;
    ConnectionManager.TRANSPORT = TRANSPORT;

    // Singleton instance
    var instance = null;

    return {
        /**
         * Get or create ConnectionManager instance
         *
         * @return {ConnectionManager} Connection manager instance
         */
        getInstance: function() {
            if (!instance) {
                instance = new ConnectionManager();
            }
            return instance;
        },

        /**
         * Initialize connection manager with session
         *
         * @param {number} sessionId Session ID
         * @param {Object} options Configuration options
         * @return {Promise} Resolves when connected
         */
        init: function(sessionId, options) {
            return this.getInstance().init(sessionId, options);
        },

        /**
         * Send message to server
         *
         * @param {string} type Message type
         * @param {Object} data Message data
         * @return {Promise} Resolves with response
         */
        send: function(type, data) {
            return this.getInstance().send(type, data);
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
        },

        /**
         * Get connection status
         *
         * @return {Object} Connection status
         */
        getStatus: function() {
            return this.getInstance().getStatus();
        },

        /**
         * Force reconnection
         *
         * @return {Promise} Resolves when reconnected
         */
        reconnect: function() {
            return this.getInstance().reconnect();
        },

        /**
         * Disconnect from server
         */
        disconnect: function() {
            this.getInstance().disconnect();
        },

        // Export constants
        STATUS: STATUS,
        TRANSPORT: TRANSPORT
    };
});
