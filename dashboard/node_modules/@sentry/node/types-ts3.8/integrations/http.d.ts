import { Hub } from '@sentry/core';
import { ClientOptions, EventProcessor, Integration, IntegrationFnResult, TracePropagationTargets } from '@sentry/types';
import { NodeClientOptions } from '../types';
interface TracingOptions {
    /**
     * List of strings/regex controlling to which outgoing requests
     * the SDK will attach tracing headers.
     *
     * By default the SDK will attach those headers to all outgoing
     * requests. If this option is provided, the SDK will match the
     * request URL of outgoing requests against the items in this
     * array, and only attach tracing headers if a match was found.
     *
     * @deprecated Use top level `tracePropagationTargets` option instead.
     * This option will be removed in v8.
     *
     * ```
     * Sentry.init({
     *   tracePropagationTargets: ['api.site.com'],
     * })
     */
    tracePropagationTargets?: TracePropagationTargets;
    /**
     * Function determining whether or not to create spans to track outgoing requests to the given URL.
     * By default, spans will be created for all outgoing requests.
     */
    shouldCreateSpanForRequest?: (url: string) => boolean;
    /**
     * This option is just for compatibility with v7.
     * In v8, this will be the default behavior.
     */
    enableIfHasTracingEnabled?: boolean;
}
interface HttpOptions {
    /**
     * Whether breadcrumbs should be recorded for requests
     * Defaults to true
     */
    breadcrumbs?: boolean;
    /**
     * Whether tracing spans should be created for requests
     * Defaults to false
     */
    tracing?: TracingOptions | boolean;
}
interface HttpIntegrationOptions {
    /**
     * Whether breadcrumbs should be recorded for requests
     * Defaults to true.
     */
    breadcrumbs?: boolean;
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
/**
 * The http module integration instruments Node's internal http module. It creates breadcrumbs, spans for outgoing
 * http requests, and attaches trace data when tracing is enabled via its `tracing` option.
 *
 * By default, this will always create breadcrumbs, and will create spans if tracing is enabled.
 */
export declare const httpIntegration: (options?: HttpIntegrationOptions | undefined) => IntegrationFnResult;
/**
 * The http module integration instruments Node's internal http module. It creates breadcrumbs, transactions for outgoing
 * http requests and attaches trace data when tracing is enabled via its `tracing` option.
 *
 * @deprecated Use `httpIntegration()` instead.
 */
export declare class Http implements Integration {
    /**
     * @inheritDoc
     */
    static id: string;
    /**
     * @inheritDoc
     */
    name: string;
    private readonly _breadcrumbs;
    private readonly _tracing;
    /**
     * @inheritDoc
     */
    constructor(options?: HttpOptions);
    /**
     * @inheritDoc
     */
    setupOnce(_addGlobalEventProcessor: (callback: EventProcessor) => void, setupOnceGetCurrentHub: () => Hub): void;
}
/** Exported for tests only. */
export declare function _shouldCreateSpans(tracingOptions: TracingOptions | undefined, clientOptions: Partial<ClientOptions> | undefined): boolean;
/** Exported for tests only. */
export declare function _getShouldCreateSpanForRequest(shouldCreateSpans: boolean, tracingOptions: TracingOptions | undefined, clientOptions: Partial<NodeClientOptions> | undefined): undefined | ((url: string) => boolean);
export {};
//# sourceMappingURL=http.d.ts.map
