import * as Types from '../types/types.js';
import type { HandlerName } from './types.js';
export declare function reset(): void;
export declare function handleEvent(_event: Types.Events.Event): void;
export declare function finalize(): Promise<void>;
export declare function data(): {
    serverTimings: Types.Events.SyntheticServerTiming[];
};
export declare function deps(): HandlerName[];
