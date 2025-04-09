export { Breadcrumb, BreadcrumbHint, PolymorphicRequest, Request, SdkInfo, Event, EventHint, Exception, Session, Severity, SeverityLevel, Span, StackFrame, Stacktrace, Thread, Transaction, User, } from '@sentry/types';
export { AddRequestDataToEventOptions, TransactionNamingScheme } from '@sentry/utils';
export { NodeOptions } from './types';
export { addGlobalEventProcessor, addEventProcessor, addBreadcrumb, addIntegration, captureException, captureEvent, captureMessage, close, configureScope, createTransport, extractTraceparentData, flush, getActiveTransaction, getHubFromCarrier, getCurrentHub, getClient, isInitialized, getCurrentScope, getGlobalScope, getIsolationScope, Hub, lastEventId, makeMain, setCurrentClient, runWithAsyncContext, Scope, startTransaction, SDK_VERSION, setContext, setExtra, setExtras, setTag, setTags, setUser, spanStatusfromHttpCode, getSpanStatusFromHttpCode, setHttpStatus, trace, withScope, withIsolationScope, captureCheckIn, withMonitor, setMeasurement, getActiveSpan, startSpan, startActiveSpan, startInactiveSpan, startSpanManual, withActiveSpan, continueTrace, parameterize, metrics, functionToStringIntegration, inboundFiltersIntegration, linkedErrorsIntegration, requestDataIntegration, startSession, captureSession, endSession, } from '@sentry/core';
export { SEMANTIC_ATTRIBUTE_SENTRY_OP, SEMANTIC_ATTRIBUTE_SENTRY_ORIGIN, SEMANTIC_ATTRIBUTE_SENTRY_SOURCE, SEMANTIC_ATTRIBUTE_SENTRY_SAMPLE_RATE, } from '@sentry/core';
export { SpanStatusType } from '@sentry/core';
export { autoDiscoverNodePerformanceMonitoringIntegrations } from './tracing';
export { NodeClient } from './client';
export { makeNodeTransport } from './transports';
export { defaultIntegrations, getDefaultIntegrations, init, defaultStackParser, getSentryRelease, } from './sdk';
export { addRequestDataToEvent, DEFAULT_USER_INCLUDES, extractRequestData } from '@sentry/utils';
export { deepReadDirSync } from './utils';
import { createGetModuleFromFilename } from './module';
/**
 * @deprecated use `createGetModuleFromFilename` instead.
 */
export declare const getModuleFromFilename: (filename: string | undefined) => string | undefined;
export { createGetModuleFromFilename };
export { enableAnrDetection } from './integrations/anr/legacy';
import * as Handlers from './handlers';
import * as NodeIntegrations from './integrations';
import * as TracingIntegrations from './tracing/integrations';
export declare const Integrations: {
    Apollo: typeof TracingIntegrations.Apollo;
    Express: typeof TracingIntegrations.Express;
    GraphQL: typeof TracingIntegrations.GraphQL;
    Mongo: typeof TracingIntegrations.Mongo;
    Mysql: typeof TracingIntegrations.Mysql;
    Postgres: typeof TracingIntegrations.Postgres;
    Prisma: typeof TracingIntegrations.Prisma;
    Console: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        setup: (client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>) => void;
    }>;
    Http: typeof NodeIntegrations.Http;
    OnUncaughtException: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        setup: (client: import("./client").NodeClient) => void;
    }> & (new (options?: Partial<{
        exitEvenIfOtherHandlersAreRegistered: boolean;
        onFatalError?(this: void, firstError: Error, secondError?: Error | undefined): void;
    }> | undefined) => import("@sentry/types").Integration);
    OnUnhandledRejection: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        setup: (client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>) => void;
    }> & (new (options?: Partial<{
        mode: "warn" | "none" | "strict";
    }> | undefined) => import("@sentry/types").Integration);
    Modules: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        processEvent: (event: import("@sentry/types").Event) => import("@sentry/types").Event;
    }>;
    ContextLines: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        processEvent: (event: import("@sentry/types").Event) => Promise<import("@sentry/types").Event>;
    }> & (new (options?: {
        frameContextLines?: number | undefined;
    } | undefined) => import("@sentry/types").Integration);
    Context: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        processEvent: (event: import("@sentry/types").Event) => Promise<import("@sentry/types").Event>;
    }> & (new (options?: {
        app?: boolean | undefined;
        os?: boolean | undefined;
        device?: boolean | {
            cpu?: boolean | undefined;
            memory?: boolean | undefined;
        } | undefined;
        culture?: boolean | undefined;
        cloudResource?: boolean | undefined;
    } | undefined) => import("@sentry/types").Integration);
    RequestData: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        processEvent: (event: import("@sentry/types").Event, hint: import("@sentry/types").EventHint, client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>) => import("@sentry/types").Event;
    }> & (new (options?: {
        include?: {
            cookies?: boolean | undefined;
            data?: boolean | undefined;
            headers?: boolean | undefined;
            ip?: boolean | undefined;
            query_string?: boolean | undefined;
            url?: boolean | undefined;
            user?: boolean | {
                id?: boolean | undefined;
                username?: boolean | undefined;
                email?: boolean | undefined;
            } | undefined;
        } | undefined;
        transactionNamingScheme?: import("@sentry/utils").TransactionNamingScheme | undefined;
    } | undefined) => import("@sentry/types").Integration);
    LocalVariables: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        processEvent: (event: import("@sentry/types").Event) => import("@sentry/types").Event;
        setup: (client: import("./client").NodeClient) => void;
    }> & (new (options?: import("./integrations/local-variables/common").LocalVariablesIntegrationOptions | undefined, session?: import("./integrations/local-variables/local-variables-sync").DebugSession | undefined) => import("@sentry/types").Integration);
    Undici: typeof NodeIntegrations.Undici;
    Spotlight: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        setup: (client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>) => void;
    }> & (new (options?: Partial<{
        sidecarUrl?: string | undefined;
    }> | undefined) => import("@sentry/types").Integration);
    Anr: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        setup: (client: import("./client").NodeClient) => void;
    }> & (new (options?: Partial<import("./integrations/anr/common").AnrIntegrationOptions> | undefined) => import("@sentry/types").Integration & {
        setup(client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>): void;
    });
    Hapi: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration>;
    FunctionToString: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        setupOnce: () => void;
    }>;
    InboundFilters: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        preprocessEvent: (event: import("@sentry/types").Event, hint: import("@sentry/types").EventHint, client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>) => void;
    }> & (new (options?: Partial<{
        allowUrls: (string | RegExp)[];
        denyUrls: (string | RegExp)[];
        ignoreErrors: (string | RegExp)[];
        ignoreTransactions: (string | RegExp)[];
        ignoreInternal: boolean;
        disableErrorDefaults: boolean;
        disableTransactionDefaults: boolean;
    }> | undefined) => import("@sentry/types").Integration);
    LinkedErrors: import("@sentry/types").IntegrationClass<import("@sentry/types").Integration & {
        preprocessEvent: (event: import("@sentry/types").Event, hint: import("@sentry/types").EventHint, client: import("@sentry/types").Client<import("@sentry/types").ClientOptions<import("@sentry/types").BaseTransportOptions>>) => void;
    }> & (new (options?: {
        key?: string | undefined;
        limit?: number | undefined;
    } | undefined) => import("@sentry/types").Integration);
};
export { captureConsoleIntegration, dedupeIntegration, debugIntegration, extraErrorDataIntegration, reportingObserverIntegration, rewriteFramesIntegration, sessionTimingIntegration, httpClientIntegration, } from '@sentry/integrations';
export { consoleIntegration } from './integrations/console';
export { onUncaughtExceptionIntegration } from './integrations/onuncaughtexception';
export { onUnhandledRejectionIntegration } from './integrations/onunhandledrejection';
export { modulesIntegration } from './integrations/modules';
export { contextLinesIntegration } from './integrations/contextlines';
export { nodeContextIntegration } from './integrations/context';
export { localVariablesIntegration } from './integrations/local-variables';
export { spotlightIntegration } from './integrations/spotlight';
export { anrIntegration } from './integrations/anr';
export { hapiIntegration } from './integrations/hapi';
export { Undici, nativeNodeFetchintegration } from './integrations/undici';
export { Http, httpIntegration } from './integrations/http';
export { LocalVariablesIntegrationOptions } from './integrations/local-variables/common';
export { DebugSession } from './integrations/local-variables/local-variables-sync';
export { AnrIntegrationOptions } from './integrations/anr/common';
export { Handlers };
export { trpcMiddleware } from './trpc';
export { hapiErrorPlugin } from './integrations/hapi';
import { instrumentCron } from './cron/cron';
import { instrumentNodeCron } from './cron/node-cron';
import { instrumentNodeSchedule } from './cron/node-schedule';
/** Methods to instrument cron libraries for Sentry check-ins */
export declare const cron: {
    instrumentCron: typeof instrumentCron;
    instrumentNodeCron: typeof instrumentNodeCron;
    instrumentNodeSchedule: typeof instrumentNodeSchedule;
};
//# sourceMappingURL=index.d.ts.map
