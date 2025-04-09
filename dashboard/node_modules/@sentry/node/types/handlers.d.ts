/// <reference types="node" />
import type * as http from 'http';
import type { AddRequestDataToEventOptions } from '@sentry/utils';
import type { ParseRequestOptions } from './requestDataDeprecated';
import { trpcMiddleware as newTrpcMiddleware } from './trpc';
/**
 * Express-compatible tracing handler.
 * @see Exposed as `Handlers.tracingHandler`
 */
export declare function tracingHandler(): (req: http.IncomingMessage, res: http.ServerResponse, next: (error?: any) => void) => void;
export type RequestHandlerOptions = (ParseRequestOptions | AddRequestDataToEventOptions) & {
    flushTimeout?: number;
};
/**
 * Express compatible request handler.
 * @see Exposed as `Handlers.requestHandler`
 */
export declare function requestHandler(options?: RequestHandlerOptions): (req: http.IncomingMessage, res: http.ServerResponse, next: (error?: any) => void) => void;
/** JSDoc */
interface MiddlewareError extends Error {
    status?: number | string;
    statusCode?: number | string;
    status_code?: number | string;
    output?: {
        statusCode?: number | string;
    };
}
/**
 * Express compatible error handler.
 * @see Exposed as `Handlers.errorHandler`
 */
export declare function errorHandler(options?: {
    /**
     * Callback method deciding whether error should be captured and sent to Sentry
     * @param error Captured middleware error
     */
    shouldHandleError?(this: void, error: MiddlewareError): boolean;
}): (error: MiddlewareError, req: http.IncomingMessage, res: http.ServerResponse, next: (error: MiddlewareError) => void) => void;
/**
 * Sentry tRPC middleware that names the handling transaction after the called procedure.
 *
 * Use the Sentry tRPC middleware in combination with the Sentry server integration,
 * e.g. Express Request Handlers or Next.js SDK.
 *
 * @deprecated Please use the top level export instead:
 * ```
 * // OLD
 * import * as Sentry from '@sentry/node';
 * Sentry.Handlers.trpcMiddleware();
 *
 * // NEW
 * import * as Sentry from '@sentry/node';
 * Sentry.trpcMiddleware();
 * ```
 */
export declare const trpcMiddleware: typeof newTrpcMiddleware;
export type { ParseRequestOptions, ExpressRequest } from './requestDataDeprecated';
export { parseRequest, extractRequestData } from './requestDataDeprecated';
//# sourceMappingURL=handlers.d.ts.map