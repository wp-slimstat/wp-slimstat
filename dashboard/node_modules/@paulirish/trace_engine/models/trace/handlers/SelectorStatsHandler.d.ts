import * as Types from '../types/types.js';
export declare function reset(): void;
export declare function handleEvent(event: Types.Events.Event): void;
export declare function finalize(): Promise<void>;
export interface SelectorStatsData {
    dataForUpdateLayoutEvent: Map<Types.Events.UpdateLayoutTree, {
        timings: Types.Events.SelectorTiming[];
    }>;
}
export declare function data(): SelectorStatsData;
