import { Event, Integration, IntegrationClass } from '@sentry/types';
import { NodeClient } from '../../client';
import { LocalVariablesIntegrationOptions } from './common';
export declare const localVariablesAsyncIntegration: (options?: LocalVariablesIntegrationOptions | undefined) => import("@sentry/types").IntegrationFnResult;
/**
 * Adds local variables to exception frames.
 * @deprecated Use `localVariablesAsyncIntegration()` instead.
 */
export declare const LocalVariablesAsync: IntegrationClass<Integration & {
    processEvent: (event: Event) => Event;
    setup: (client: NodeClient) => void;
}>;
export type LocalVariablesAsync = typeof LocalVariablesAsync;
//# sourceMappingURL=local-variables-async.d.ts.map
