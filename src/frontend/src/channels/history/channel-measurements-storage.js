import {openDB} from "idb";
import {DateTime} from "luxon";
import {CHART_TYPES, fillGaps} from "@/channels/history/channel-measurements-history-chart-strategies";

export class IndexedDbMeasurementLogsStorage {
    constructor(channel) {
        this.channel = channel;
        this.chartStrategy = CHART_TYPES[this.channel.function.name];
        if (window.indexedDB) {
            this.db = openDB(`channel_measurement_logs_${this.channel.id}`, 2, {
                upgrade(db) {
                    if (!db.objectStoreNames.contains("logs")) {
                        const os = db.createObjectStore("logs", {keyPath: 'date_timestamp'});
                        os.createIndex("date", "date", {unique: true});
                    }
                }
            });
        }
    }

    async checkSupport() {
        try {
            await (await this.db).count('logs');
            return this.hasSupport = true;
        } catch (e) {
            return this.hasSupport = false;
        }
    }

    adjustLogsBeforeStorage(logs) {
        logs = logs.map(log => {
            log.date_timestamp = +log.date_timestamp;
            log.date = DateTime.fromSeconds(log.date_timestamp).toJSDate();
            return log;
        });
        logs = this.chartStrategy.adjustLogs(logs);
        return logs.map(log => this.chartStrategy.fixLog(log));
    }

    async getSparseLogsAggregationStrategy() {
        const oldestLog = await this.getOldestLog();
        if (!oldestLog) {
            return 'minute';
        }
        const newestLog = await this.getNewestLog();
        const availableStrategies = this.getAvailableAggregationStrategies(newestLog.date_timestamp - oldestLog.date_timestamp);
        return availableStrategies.pop();
    }

    async fetchSparseLogs() {
        const aggregationStrategy = await this.getSparseLogsAggregationStrategy();
        const sparseLogs = await this.fetchDenseLogs(0, DateTime.now().toSeconds(), aggregationStrategy);
        return sparseLogs;
    }

    getAvailableAggregationStrategies(timestampRange) {
        const strategies = [];
        if (timestampRange < 86400 * 3) {
            strategies.push('minute');
        }
        if (timestampRange > 3600 * 6 && timestampRange < 86400 * 7) {
            strategies.push('hour');
        }
        if (timestampRange > 86400 * 5 && timestampRange < 86400 * 365) {
            strategies.push('day');
        }
        if (timestampRange > 86400 * 60) {
            strategies.push('month');
        }
        return strategies;
    }

    async getNewestLog() {
        const index = (await this.db).transaction('logs').store.index('date');
        const cursor = await index.openCursor(null, 'prev');
        return cursor?.value;
    }

    async getOldestLog() {
        const index = (await this.db).transaction('logs').store.index('date');
        const cursor = await index.openCursor(null);
        return cursor?.value;
    }

    async fetchDenseLogs(afterTimestamp, beforeTimestamp, aggregationMethod = 'minute') {
        const fromDate = DateTime.fromSeconds(afterTimestamp).startOf(aggregationMethod).toJSDate();
        const toDate = DateTime.fromSeconds(beforeTimestamp).endOf(aggregationMethod).toJSDate();
        const range = IDBKeyRange.bound(fromDate, toDate);
        const logs = await (await this.db).getAllFromIndex('logs', 'date', range);
        const keyFunc = {
            hour: (log) => `${log.date.getFullYear()}_${log.date.getMonth()}_${log.date.getDate()}_${log.date.getHours()}`,
            day: (log) => `${log.date.getFullYear()}_${log.date.getMonth()}_${log.date.getDate()}`,
            month: (log) => `${log.date.getFullYear()}_${log.date.getMonth()}`,
        }[aggregationMethod];
        if (keyFunc) {
            const aggregatedLogsKeys = {};
            const aggregatedLogs = [];
            logs.forEach(log => {
                const key = keyFunc(log);
                if (aggregatedLogsKeys[key] === undefined) {
                    aggregatedLogsKeys[key] = aggregatedLogs.length;
                    aggregatedLogs.push([]);
                }
                aggregatedLogs[aggregatedLogsKeys[key]].push(log);
            });
            const finalLogs = aggregatedLogs
                .map(this.chartStrategy.aggregateLogs)
                .map(log => {
                    log.date = DateTime.fromJSDate(log.date).startOf(aggregationMethod).toJSDate();
                    log.date_timestamp = Math.floor(log.date.getTime() / 1000);
                    return log;
                });
            return finalLogs;
        } else {
            return logs;
        }
    }

    async init(vue) {
        return vue.$http.get(`channels/${this.channel.id}/measurement-logs?order=DESC&limit=1000`)
            .then(async ({body: logItems}) => {
                if (logItems.length) {
                    logItems.reverse();
                    if (this.hasSupport) {
                        const existingLog = await (await this.db).get('logs', logItems[0].date_timestamp);
                        if (!existingLog) {
                            await (await this.db).clear('logs');
                        }
                        await this.storeLogs(logItems);
                    }
                }
                if (logItems.length < 10 || !this.hasSupport) {
                    this.isReady = true;
                }
                return logItems;
            });
    }

    async fetchOlderLogs(vue, progressCallback, somethingDownloaded = false) {
        const oldestLog = await this.getOldestLog();
        if (oldestLog) {
            const beforeTimestamp = +oldestLog.date_timestamp - 300;
            return vue.$http.get(`channels/${this.channel.id}/measurement-logs?order=DESC&limit=2500&beforeTimestamp=${beforeTimestamp}`)
                .then(async ({body: logItems, headers}) => {
                    if (logItems.length) {
                        const totalCount = +headers.get('X-Total-Count');
                        const savedCount = await (await this.db).count('logs');
                        logItems.reverse();
                        await this.storeLogs(logItems);
                        progressCallback(savedCount * 100 / totalCount);
                        return this.fetchOlderLogs(vue, progressCallback, true);
                    } else {
                        this.isReady = true;
                    }
                    return somethingDownloaded;
                });
        }
    }

    async storeLogs(logs) {
        logs = fillGaps(logs, 600, this.chartStrategy.emptyLog());
        logs = this.chartStrategy.interpolateGaps(logs);
        const tx = (await this.db).transaction('logs', 'readwrite');
        logs = this.adjustLogsBeforeStorage(logs);
        // the following if-s mitigate risk of bad-filled gaps when fetching logs by pages
        if (logs.length > 100) {
            logs.splice(0, 15);
        }
        if (logs.length > 1) {
            logs.shift();
        }
        logs.forEach(async (log) => {
            await tx.store.put(log);
        });
        await tx.done;
    }
}
