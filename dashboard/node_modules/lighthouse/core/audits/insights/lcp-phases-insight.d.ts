export default LCPPhasesInsight;
declare class LCPPhasesInsight extends Audit {
    /**
     * @param {Required<import('@paulirish/trace_engine/models/trace/insights/LCPPhases.js').LCPPhasesInsightModel>['phases']} phases
     * @return {LH.Audit.Details.Table}
     */
    static makePhaseTable(phases: Required<import("@paulirish/trace_engine/models/trace/insights/LCPPhases.js").LCPPhasesInsightModel>["phases"]): LH.Audit.Details.Table;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=lcp-phases-insight.d.ts.map