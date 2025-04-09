import { Client, Integration, IntegrationClass, IntegrationFnResult } from '@sentry/types';
import { NodeClient } from '../../client';
import { AnrIntegrationOptions } from './common';
type AnrInternal = {
    startWorker: () => void;
    stopWorker: () => void;
};
type AnrReturn = (options?: Partial<AnrIntegrationOptions>) => IntegrationFnResult & AnrInternal;
export declare const anrIntegration: AnrReturn;
/**
 * Starts a thread to detect App Not Responding (ANR) events
 *
 * ANR detection requires Node 16.17.0 or later
 *
 * @deprecated Use `anrIntegration()` instead.
 */
export declare const Anr: IntegrationClass<Integration & {
    setup: (client: NodeClient) => void;
}> & (new (options?: Partial<AnrIntegrationOptions>) => Integration & {
    setup(client: Client): void;
});
export type Anr = typeof Anr;
export {};
//# sourceMappingURL=index.d.ts.map
