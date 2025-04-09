import * as Types from '../types/types.js';
export declare function reset(): void;
export declare function handleEvent(event: Types.Events.Event): void;
export declare function finalize(): Promise<void>;
export interface ImagePaintData {
    paintImageByDrawLazyPixelRef: Map<number, Types.Events.PaintImage>;
    paintImageForEvent: Map<Types.Events.Event, Types.Events.PaintImage>;
    paintImageEventForUrl: Map<string, Types.Events.PaintImage[]>;
}
export declare function data(): ImagePaintData;
