import {defineStore} from "pinia";
import {computed, ref} from "vue";
import {channelsApi} from "@/api/channels-api";
import {useIntervalFn} from "@vueuse/core";
import {useDevicesStore} from "@/stores/devices-store";

export const useChannelsStore = defineStore('channels', () => {
    const all = ref({});
    const ids = ref([]);

    const fetchAll = (force = false) => {
        if (fetchAll.promise && !force) {
            return fetchAll.promise;
        } else {
            return fetchAll.promise = channelsApi.getListWithState().then((channels) => {
                const state = channels.reduce((acc, curr) => {
                    return {
                        ids: acc.ids.concat(curr.id),
                        all: {...acc.all, [curr.id]: curr}
                    }
                }, {ids: [], all: {}});
                all.value = state.all;
                ids.value = state.ids;
            })
        }
    };

    const updateChannel = (channel) => {
        all.value[channel.id] = {...all.value[channel.id], ...channel};
    }

    const fetchChannel = (channelId) => {
        return channelsApi.getOneWithState(channelId).then(updateChannel);
    };

    const fetchStates = () => {
        return channelsApi.getStates().then((response) => {
            let refetch = false;
            const idsToFetch = [];
            const devicesStore = useDevicesStore();
            // const {states: channelsStates, devicesCount} = response;
            // TODO ^ uncomment after full 24.10 update
            // TODO v remove after full 24.10 update
            let channelsStates;
            let devicesCount;
            if (response.states) {
                channelsStates = response.states;
                devicesCount = response.devicesCount;
            } else {
                channelsStates = response;
                devicesCount = devicesStore.ids.length;
            }
            // TODO ^ REMOVE AFTER 24.10 update
            devicesStore.updateConnectedStatuses(channelsStates);
            channelsStates.forEach((channel) => {
                if (all.value[channel.id]) {
                    all.value[channel.id].connected = channel.state.connected;
                    all.value[channel.id].state = channel.state;
                    if (all.value[channel.id].checksum !== channel.checksum) {
                        idsToFetch.push(channel.id);
                    }
                } else {
                    refetch = true;
                }
            });
            refetch = refetch || channelsStates.length !== ids.value.length || devicesCount !== devicesStore.ids.length;
            if (refetch || idsToFetch.length > 5) {
                refetchAll();
            } else if (idsToFetch.length > 0) {
                idsToFetch.forEach((id) => fetchChannel(id));
            }
        });
    };

    const refetchAll = () => {
        fetchAll(true);
        const devicesStore = useDevicesStore();
        devicesStore.fetchAll(true);
    }

    useIntervalFn(() => fetchStates(), 7777);

    const list = computed(() => ids.value.map(id => all.value[id]));

    const $reset = () => {
        all.value = {};
        ids.value = [];
        fetchAll.promise = undefined;
    };

    return {all, ids, list, $reset, fetchAll, refetchAll, fetchStates};
});