import type * as Types from '../types/types.js';
export declare class SyntheticEventsManager {
    #private;
    static activate(manager: SyntheticEventsManager): void;
    static createAndActivate(rawEvents: readonly Types.Events.Event[]): SyntheticEventsManager;
    static getActiveManager(): SyntheticEventsManager;
    static reset(): void;
    static registerSyntheticEvent<T extends Types.Events.SyntheticBased>(syntheticEvent: Omit<T, '_tag'>): T;
    static registerServerTiming(syntheticEvent: Omit<Types.Events.SyntheticServerTiming, '_tag'>): Types.Events.SyntheticServerTiming;
    private constructor();
    /**
     * Registers and returns a branded synthetic event. Synthetic events need to
     * be created with this method to ensure they are registered and made
     * available to load events using serialized keys.
     */
    registerSyntheticEvent<T extends Types.Events.SyntheticBased>(syntheticEvent: Omit<T, '_tag'>): T;
    syntheticEventForRawEventIndex(rawEventIndex: number): Types.Events.SyntheticBased;
    getSyntheticTraces(): Types.Events.SyntheticBased[];
    getRawTraceEvents(): readonly Types.Events.Event[];
}
