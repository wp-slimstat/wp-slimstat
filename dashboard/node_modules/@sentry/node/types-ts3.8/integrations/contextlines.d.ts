import { Event, Integration, IntegrationClass } from '@sentry/types';
/**
 * Resets the file cache. Exists for testing purposes.
 * @hidden
 */
export declare function resetFileContentCache(): void;
interface ContextLinesOptions {
    /**
     * Sets the number of context lines for each frame when loading a file.
     * Defaults to 7.
     *
     * Set to 0 to disable loading and inclusion of source files.
     **/
    frameContextLines?: number;
}
export declare const contextLinesIntegration: (options?: ContextLinesOptions | undefined) => import("@sentry/types").IntegrationFnResult;
/**
 * Add node modules / packages to the event.
 * @deprecated Use `contextLinesIntegration()` instead.
 */
export declare const ContextLines: IntegrationClass<Integration & {
    processEvent: (event: Event) => Promise<Event>;
}> & (new (options?: {
    frameContextLines?: number;
}) => Integration);
export type ContextLines = typeof ContextLines;
export {};
//# sourceMappingURL=contextlines.d.ts.map
