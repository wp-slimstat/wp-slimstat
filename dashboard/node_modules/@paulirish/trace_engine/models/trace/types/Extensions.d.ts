import type { Args, ConsoleTimeStamp, Event, PerformanceMark, PerformanceMeasureBegin, Phase, SyntheticBased } from './TraceEvents.js';
export type ExtensionEntryType = 'track-entry' | 'marker';
export declare const extensionPalette: readonly ["primary", "primary-light", "primary-dark", "secondary", "secondary-light", "secondary-dark", "tertiary", "tertiary-light", "tertiary-dark", "error", "warning"];
export type ExtensionColorFromPalette = typeof extensionPalette[number];
export interface ExtensionDataPayloadBase {
    color?: ExtensionColorFromPalette;
    properties?: Array<[string, string]>;
    tooltipText?: string;
}
export type ExtensionDataPayload = ExtensionTrackEntryPayload | ExtensionMarkerPayload;
export interface ExtensionTrackEntryPayload extends ExtensionDataPayloadBase {
    dataType?: 'track-entry';
    track: string;
    trackGroup?: string;
}
export interface ExtensionMarkerPayload extends ExtensionDataPayloadBase {
    dataType: 'marker';
}
/**
 * Synthetic events created for extension tracks.
 */
export interface SyntheticExtensionTrackEntry extends SyntheticBased<Phase.COMPLETE, PerformanceMeasureBegin | PerformanceMark | ConsoleTimeStamp> {
    args: Args & ExtensionTrackEntryPayload;
}
/**
 * Synthetic events created for extension marks.
 */
export interface SyntheticExtensionMarker extends SyntheticBased<Phase.COMPLETE, PerformanceMark> {
    args: Args & ExtensionMarkerPayload;
}
export type SyntheticExtensionEntry = SyntheticExtensionTrackEntry | SyntheticExtensionMarker;
export declare function isExtensionPayloadMarker(payload: {
    dataType?: string;
}): payload is ExtensionMarkerPayload;
export declare function isExtensionPayloadTrackEntry(payload: {
    track?: string;
    dataType?: string;
}): payload is ExtensionTrackEntryPayload;
export declare function isValidExtensionPayload(payload: {
    track?: string;
    dataType?: string;
}): payload is ExtensionDataPayload;
export declare function isSyntheticExtensionEntry(entry: Event): entry is SyntheticExtensionEntry;
export interface ExtensionTrackData {
    name: string;
    isTrackGroup: boolean;
    entriesByTrack: {
        [x: string]: SyntheticExtensionTrackEntry[];
    };
}
