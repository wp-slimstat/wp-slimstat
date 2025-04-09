import type { EventProcessor, Integration, IntegrationFnResult } from '@sentry/types';
export declare enum ChannelName {
    RequestCreate = "undici:request:create",
    RequestEnd = "undici:request:headers",
    RequestError = "undici:request:error"
}
export interface UndiciOptions {
    /**
     * Whether breadcrumbs should be recorded for requests
     * Defaults to true
     */
    breadcrumbs: boolean;
    /**
     * Whether tracing spans should be created for requests
     * If not set, this will be enabled/disabled based on if tracing is enabled.
     */
    tracing?: boolean;
    /**
     * Function determining whether or not to create spans to track outgoing requests to the given URL.
     * By default, spans will be created for all outgoing requests.
     */
    shouldCreateSpanForRequest?: (url: string) => boolean;
}
export declare const nativeNodeFetchintegration: (options?: Partial<UndiciOptions> | undefined) => IntegrationFnResult;
/**
 * Instruments outgoing HTTP requests made with the `undici` package via
 * Node's `diagnostics_channel` API.
 *
 * Supports Undici 4.7.0 or higher.
 *
 * Requires Node 16.17.0 or higher.
 *
 * @deprecated Use `nativeNodeFetchintegration()` instead.
 */
export declare class Undici implements Integration {
    /**
     * @inheritDoc
     */
    static id: string;
    /**
     * @inheritDoc
     */
    name: string;
    private readonly _options;
    private readonly _createSpanUrlMap;
    private readonly _headersUrlMap;
    constructor(_options?: Partial<UndiciOptions>);
    /**
     * @inheritDoc
     */
    setupOnce(_addGlobalEventProcessor: (callback: EventProcessor) => void): void;
    /** Helper that wraps shouldCreateSpanForRequest option */
    private _shouldCreateSpan;
    private _onRequestCreate;
    private _onRequestEnd;
    private _onRequestError;
}
//# sourceMappingURL=index.d.ts.map