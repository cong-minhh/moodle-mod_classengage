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
 * JavaScript for Chart.js visualizations in analytics
 *
 * This module handles:
 * - Engagement timeline line chart with peak/dip highlighting
 * - Concept difficulty horizontal bar chart with color coding
 * - Participation distribution doughnut chart
 * - Responsive chart configurations
 * - Tooltips with detailed information
 * - WCAG AA compliant color schemes
 * - Text alternatives for accessibility
 *
 * @module     mod_classengage/analytics_charts
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/chartjs', 'core/str'],
    function(Chart, Str) {

        // Chart configuration constants
        const CHART_CONFIG = {
            LABEL_MAX_LENGTH: 40,
            POINT_RADIUS_HIGHLIGHT: 6,
            POINT_RADIUS_NORMAL: 3,
            POINT_RADIUS_HOVER: 8,
            LINE_TENSION: 0.3,
            TITLE_FONT_SIZE: 16,

            // Difficulty thresholds
            DIFFICULTY_EASY_THRESHOLD: 70,
            DIFFICULTY_MODERATE_THRESHOLD: 50,
        };

        // Color constants with WCAG AA contrast compliance
        const COLORS = {
        // Engagement timeline colors
            TIMELINE_LINE: '#0f6cbf', // Primary blue
            TIMELINE_PEAK: '#28a745', // Success green
            TIMELINE_DIP: '#fd7e14', // Warning orange
            TIMELINE_BACKGROUND: 'rgba(15, 108, 191, 0.1)',

            // Concept difficulty colors
            DIFFICULTY_EASY: '#28a745', // Green (>70%)
            DIFFICULTY_MODERATE: '#ffc107', // Yellow (50-70%)
            DIFFICULTY_HARD: '#dc3545', // Red (<50%)

            // Participation distribution colors
            PARTICIPATION_HIGH: '#28a745', // Green (5+ responses)
            PARTICIPATION_MODERATE: '#17a2b8', // Blue (2-4 responses)
            PARTICIPATION_LOW: '#ffc107', // Yellow (1 response)
            PARTICIPATION_NONE: '#6c757d', // Gray (0 responses)
        };

        // Chart instances storage
        let chartInstances = {
            timeline: null,
            difficulty: null,
            distribution: null,
        };

        /**
     * Get canvas context or return null if not found
     *
     * @param {string} canvasId Canvas element ID
     * @returns {Object|null} Object with canvas and context, or null if not found
     * @private
     */
        const getCanvasContext = function(canvasId) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) {
            // eslint-disable-next-line no-console
                console.warn('Canvas ' + canvasId + ' not found');
                return null;
            }
            return {
                canvas: canvas,
                ctx: canvas.getContext('2d'),
            };
        };

        /**
     * Destroy chart instance if it exists
     *
     * @param {string} chartKey Key in chartInstances object
     * @private
     */
        const destroyChartIfExists = function(chartKey) {
            if (chartInstances[chartKey]) {
                chartInstances[chartKey].destroy();
                chartInstances[chartKey] = null;
            }
        };

        /**
     * Get color for difficulty level based on correctness rate
     *
     * @param {number} correctnessRate Correctness rate percentage
     * @returns {string} Color hex code
     * @private
     */
        const getDifficultyColor = function(correctnessRate) {
            if (correctnessRate >= CHART_CONFIG.DIFFICULTY_EASY_THRESHOLD) {
                return COLORS.DIFFICULTY_EASY;
            } else if (correctnessRate >= CHART_CONFIG.DIFFICULTY_MODERATE_THRESHOLD) {
                return COLORS.DIFFICULTY_MODERATE;
            }
            return COLORS.DIFFICULTY_HARD;
        };

        /**
     * Get common chart options
     *
     * @param {string} title Chart title
     * @param {Object} additionalOptions Additional chart-specific options
     * @returns {Object} Chart options object
     * @private
     */
        const getCommonChartOptions = function(title, additionalOptions) {
            const baseOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: title,
                        font: {
                            size: CHART_CONFIG.TITLE_FONT_SIZE,
                            weight: 'bold',
                        },
                    },
                },
            };

            // Merge additional options
            if (additionalOptions) {
                if (additionalOptions.plugins) {
                    Object.assign(baseOptions.plugins, additionalOptions.plugins);
                }
                Object.keys(additionalOptions).forEach(function(key) {
                    if (key !== 'plugins') {
                        baseOptions[key] = additionalOptions[key];
                    }
                });
            }

            return baseOptions;
        };

        /**
     * Initialize all charts by reading data from data attribute
     */
        const init = function() {
        // Get chart data from data attribute
            const dataElement = document.getElementById('analytics-chart-data');
            if (!dataElement) {
            // eslint-disable-next-line no-console
                console.warn('Chart data element not found');
                return;
            }

            const chartDataJson = dataElement.getAttribute('data-chartdata');
            if (!chartDataJson) {
            // eslint-disable-next-line no-console
                console.warn('Chart data attribute is empty');
                return;
            }

            let chartData;
            try {
                chartData = JSON.parse(chartDataJson);
            } catch (e) {
            // eslint-disable-next-line no-console
                console.error('Failed to parse chart data:', e);
                return;
            }

            // Validate input
            if (!chartData || typeof chartData !== 'object') {
            // eslint-disable-next-line no-console
                console.error('Invalid chart data provided');
                return;
            }

            if (chartData.timeline && Array.isArray(chartData.timeline)) {
                initEngagementTimeline(chartData.timeline);
            }

            if (chartData.difficulty && Array.isArray(chartData.difficulty)) {
                initConceptDifficulty(chartData.difficulty);
            }

            if (chartData.distribution && typeof chartData.distribution === 'object') {
                initParticipationDistribution(chartData.distribution);
            }
        };

        /**
     * Initialize engagement timeline line chart
     *
     * @param {Array} timelineData Timeline data with intervals, counts, peaks, and dips
     * @private
     */
        const initEngagementTimeline = function(timelineData) {
            const canvasData = getCanvasContext('engagement-timeline-chart');
            if (!canvasData) {
                return;
            }

            // Destroy existing chart if it exists
            destroyChartIfExists('timeline');

            // Prepare data - optimize by using single reduce instead of multiple maps
            const chartData = timelineData.reduce(function(acc, interval) {
                acc.labels.push(interval.label);
                acc.counts.push(interval.count);

                if (interval.is_peak) {
                    acc.pointBackgroundColors.push(COLORS.TIMELINE_PEAK);
                    acc.pointRadius.push(CHART_CONFIG.POINT_RADIUS_HIGHLIGHT);
                } else if (interval.is_dip) {
                    acc.pointBackgroundColors.push(COLORS.TIMELINE_DIP);
                    acc.pointRadius.push(CHART_CONFIG.POINT_RADIUS_HIGHLIGHT);
                } else {
                    acc.pointBackgroundColors.push(COLORS.TIMELINE_LINE);
                    acc.pointRadius.push(CHART_CONFIG.POINT_RADIUS_NORMAL);
                }

                return acc;
            }, {
                labels: [],
                counts: [],
                pointBackgroundColors: [],
                pointRadius: [],
            });

            // Get language strings for accessibility
            Str.get_strings([
                {key: 'engagementtimeline', component: 'mod_classengage'},
                {key: 'timelinepeak', component: 'mod_classengage'},
                {key: 'timelinedip', component: 'mod_classengage'},
            ]).then(function(strings) {
                const chartTitle = strings[0];
                const peakLabel = strings[1];
                const dipLabel = strings[2];

                // Build chart options
                const options = getCommonChartOptions(chartTitle, {
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const interval = timelineData[context.dataIndex];
                                    let label = context.dataset.label + ': ' + context.parsed.y;

                                    if (interval.is_peak) {
                                        label += ' (' + peakLabel + ')';
                                    } else if (interval.is_dip) {
                                        label += ' (' + dipLabel + ')';
                                    }

                                    return label;
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Time',
                            },
                            grid: {
                                display: false,
                            },
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Response Count',
                            },
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                            },
                        },
                    },
                });

                // Create chart
                chartInstances.timeline = new Chart(canvasData.ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: chartTitle,
                            data: chartData.counts,
                            borderColor: COLORS.TIMELINE_LINE,
                            backgroundColor: COLORS.TIMELINE_BACKGROUND,
                            pointBackgroundColor: chartData.pointBackgroundColors,
                            pointBorderColor: chartData.pointBackgroundColors,
                            pointRadius: chartData.pointRadius,
                            pointHoverRadius: CHART_CONFIG.POINT_RADIUS_HOVER,
                            fill: true,
                            tension: CHART_CONFIG.LINE_TENSION,
                        }],
                    },
                    options: options,
                });

                // Add text alternative for accessibility
                canvasData.canvas.setAttribute('aria-label', chartTitle + '. ' + generateTimelineTextAlternative(timelineData));

                return true;
            }).catch(function(error) {
            // eslint-disable-next-line no-console
                console.error('Failed to load language strings for timeline chart:', error);
            });
        };

        /**
     * Initialize concept difficulty horizontal bar chart
     *
     * @param {Array} difficultyData Concept difficulty data with questions and correctness rates
     * @private
     */
        const initConceptDifficulty = function(difficultyData) {
            const canvasData = getCanvasContext('concept-difficulty-chart');
            if (!canvasData) {
                return;
            }

            // Destroy existing chart if it exists
            destroyChartIfExists('difficulty');

            // Prepare data - truncate long question texts and assign colors
            const labels = difficultyData.map(function(concept) {
                const text = concept.question_text;
                return text.length > CHART_CONFIG.LABEL_MAX_LENGTH ?
                    text.substring(0, CHART_CONFIG.LABEL_MAX_LENGTH) + '...' : text;
            });

            const correctnessRates = difficultyData.map(function(concept) {
                return concept.correctness_rate;
            });

            // Color code based on difficulty using helper function
            const backgroundColors = difficultyData.map(function(concept) {
                return getDifficultyColor(concept.correctness_rate);
            });

            // Get language strings
            Str.get_strings([
                {key: 'conceptdifficulty', component: 'mod_classengage'},
            ]).then(function(strings) {
                const chartTitle = strings[0];

                // Build chart options
                const options = getCommonChartOptions(chartTitle, {
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                // Show full question text in tooltip
                                    return difficultyData[context[0].dataIndex].question_text;
                                },
                                label: function(context) {
                                    return 'Correctness: ' + context.parsed.x.toFixed(1) + '%';
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Correctness Rate (%)',
                            },
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                            },
                        },
                        y: {
                            title: {
                                display: false,
                            },
                        },
                    },
                });

                // Create chart
                chartInstances.difficulty = new Chart(canvasData.ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Correctness Rate (%)',
                            data: correctnessRates,
                            backgroundColor: backgroundColors,
                            borderColor: backgroundColors,
                            borderWidth: 1,
                        }],
                    },
                    options: options,
                });

                // Add text alternative for accessibility
                canvasData.canvas.setAttribute('aria-label', chartTitle + '. ' + generateDifficultyTextAlternative(difficultyData));

                return true;
            }).catch(function(error) {
            // eslint-disable-next-line no-console
                console.error('Failed to load language strings for difficulty chart:', error);
            });
        };

        /**
     * Initialize participation distribution doughnut chart
     *
     * @param {Object} distributionData Participation distribution data with counts per category
     * @private
     */
        const initParticipationDistribution = function(distributionData) {
            const canvasData = getCanvasContext('participation-distribution-chart');
            if (!canvasData) {
                return;
            }

            // Destroy existing chart if it exists
            destroyChartIfExists('distribution');

            // Get language strings
            Str.get_strings([
                {key: 'participationdistribution', component: 'mod_classengage'},
                {key: 'participationhigh', component: 'mod_classengage'},
                {key: 'participationmoderate', component: 'mod_classengage'},
                {key: 'participationlow', component: 'mod_classengage'},
                {key: 'participationnone', component: 'mod_classengage'},
            ]).then(function(strings) {
                const chartTitle = strings[0];
                const highLabel = strings[1];
                const moderateLabel = strings[2];
                const lowLabel = strings[3];
                const noneLabel = strings[4];

                const labels = [highLabel, moderateLabel, lowLabel, noneLabel];
                const data = [
                    distributionData.high || 0,
                    distributionData.moderate || 0,
                    distributionData.low || 0,
                    distributionData.none || 0,
                ];

                const backgroundColors = [
                    COLORS.PARTICIPATION_HIGH,
                    COLORS.PARTICIPATION_MODERATE,
                    COLORS.PARTICIPATION_LOW,
                    COLORS.PARTICIPATION_NONE,
                ];

                const totalStudents = data.reduce(function(sum, count) {
                    return sum + count;
                }, 0);

                // Build chart options
                const options = getCommonChartOptions(chartTitle, {
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const count = context.parsed;
                                    const percentage = totalStudents > 0
                                        ? ((count / totalStudents) * 100).toFixed(1)
                                        : 0;
                                    return context.label + ': ' + count + ' (' + percentage + '%)';
                                },
                            },
                        },
                    },
                });

                // Create chart
                chartInstances.distribution = new Chart(canvasData.ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: backgroundColors,
                            borderColor: '#fff',
                            borderWidth: 2,
                        }],
                    },
                    options: options,
                });

                // Add text alternative for accessibility
                var altText = chartTitle + '. ' + generateDistributionTextAlternative(distributionData);
                canvasData.canvas.setAttribute('aria-label', altText);

                return true;
            }).catch(function(error) {
            // eslint-disable-next-line no-console
                console.error('Failed to load language strings for distribution chart:', error);
            });
        };

        /**
     * Generate text alternative for engagement timeline chart
     *
     * @param {Array} timelineData Timeline data
     * @returns {String} Text description of the chart
     * @private
     */
        const generateTimelineTextAlternative = function(timelineData) {
            if (!timelineData || timelineData.length === 0) {
                return 'No timeline data available.';
            }

            const totalIntervals = timelineData.length;
            const totalResponses = timelineData.reduce((sum, interval) => sum + interval.count, 0);
            const avgResponses = (totalResponses / totalIntervals).toFixed(1);

            const peaks = timelineData.filter(interval => interval.is_peak);
            const dips = timelineData.filter(interval => interval.is_dip);

            let description = `Timeline shows ${totalIntervals} intervals with ${totalResponses} total responses, ` +
            `averaging ${avgResponses} responses per interval.`;

            if (peaks.length > 0) {
                description += ` Peak engagement at ${peaks.map(p => p.label).join(', ')}.`;
            }

            if (dips.length > 0) {
                description += ` Attention dips at ${dips.map(d => d.label).join(', ')}.`;
            }

            return description;
        };

        /**
     * Generate text alternative for concept difficulty chart
     *
     * @param {Array} difficultyData Difficulty data
     * @returns {String} Text description of the chart
     * @private
     */
        const generateDifficultyTextAlternative = function(difficultyData) {
            if (!difficultyData || difficultyData.length === 0) {
                return 'No concept difficulty data available.';
            }

            const totalConcepts = difficultyData.length;
            const avgCorrectness = (difficultyData.reduce((sum, c) => sum + c.correctness_rate, 0) / totalConcepts).toFixed(1);

            const difficult = difficultyData.filter(c => c.correctness_rate < 50).length;
            const moderate = difficultyData.filter(c => c.correctness_rate >= 50 && c.correctness_rate < 70).length;
            const easy = difficultyData.filter(c => c.correctness_rate >= 70).length;

            return `Chart shows ${totalConcepts} concepts with average correctness of ${avgCorrectness}%. ` +
            `${difficult} difficult, ${moderate} moderate, ${easy} well-understood.`;
        };

        /**
     * Generate text alternative for participation distribution chart
     *
     * @param {Object} distributionData Distribution data
     * @returns {String} Text description of the chart
     * @private
     */
        const generateDistributionTextAlternative = function(distributionData) {
            const high = distributionData.high || 0;
            const moderate = distributionData.moderate || 0;
            const low = distributionData.low || 0;
            const none = distributionData.none || 0;
            const total = high + moderate + low + none;

            if (total === 0) {
                return 'No participation data available.';
            }

            return `Distribution of ${total} students: ${high} high participation, ` +
            `${moderate} moderate, ${low} low, ${none} no participation.`;
        };

        /**
     * Destroy all chart instances
     * Useful for cleanup when navigating away or refreshing data
     */
        const destroyCharts = function() {
            Object.keys(chartInstances).forEach(function(key) {
                if (chartInstances[key]) {
                    chartInstances[key].destroy();
                    chartInstances[key] = null;
                }
            });
        };

        // Public API
        return {
            init: init,
            initEngagementTimeline: initEngagementTimeline,
            initConceptDifficulty: initConceptDifficulty,
            initParticipationDistribution: initParticipationDistribution,
            destroyCharts: destroyCharts,
        };
    });
