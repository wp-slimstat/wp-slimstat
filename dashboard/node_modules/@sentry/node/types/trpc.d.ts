interface SentryTrpcMiddlewareOptions {
    /** Whether to include procedure inputs in reported events. Defaults to `false`. */
    attachRpcInput?: boolean;
}
interface TrpcMiddlewareArguments<T> {
    path: string;
    type: string;
    next: () => T;
    rawInput: unknown;
}
/**
 * Sentry tRPC middleware that names the handling transaction after the called procedure.
 *
 * Use the Sentry tRPC middleware in combination with the Sentry server integration,
 * e.g. Express Request Handlers or Next.js SDK.
 */
export declare function trpcMiddleware(options?: SentryTrpcMiddlewareOptions): <T>({ path, type, next, rawInput }: TrpcMiddlewareArguments<T>) => T;
export {};
//# sourceMappingURL=trpc.d.ts.map