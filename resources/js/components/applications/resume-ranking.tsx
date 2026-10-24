import { CheckCircle2, CircleDashed, CircleSlash } from "lucide-react";
import { Badge } from "@/components/ui/badge";

type Coverage = "strong" | "partial" | "gap";

type Counts = Record<Coverage, number>;

export type RankedResume = {
    ulid: string;
    name: string;
    is_default: boolean;
    must: Counts;
    nice: Counts;
    requirements: {
        kind: "must" | "nice";
        index: number;
        requirement: string;
        coverage: Coverage;
        library_coverage: Coverage;
        missing_from: "library" | "experience" | null;
        evidence: { status: string; text: string }[];
    }[];
};

export type ResumeRankingData = {
    resumes: RankedResume[];
    stale: boolean;
};

const coverageBadge = {
    strong: { icon: CheckCircle2, label: "Strong", variant: "default" },
    partial: { icon: CircleDashed, label: "Partial", variant: "secondary" },
    gap: { icon: CircleSlash, label: "Gap", variant: "destructive" },
} as const;

const total = (counts: Counts) => counts.strong + counts.partial + counts.gap;

/**
 * Why the first resume ranks above the second: the first rule of the ranking that separates them.
 */
function reasonFor(first: RankedResume, second: RankedResume | undefined) {
    if (second === undefined) {
        return "Your only resume.";
    }

    if (first.must.strong !== second.must.strong) {
        return `Shows the most must-haves with strong evidence (${first.must.strong} of ${total(first.must)}).`;
    }

    if (first.must.partial !== second.must.partial) {
        return `Ties on strong must-haves, and covers more of the rest in part (${first.must.partial}).`;
    }

    if (
        first.nice.strong !== second.nice.strong ||
        first.nice.partial !== second.nice.partial
    ) {
        return "Ties on must-haves, and covers more nice-to-haves.";
    }

    return first.is_default
        ? "Ties with another resume; this is your default."
        : "Ties with another resume; first by name.";
}

/**
 * The resumes ranked by how well their own bullet points cover the analysed requirements,
 * best first, with each requirement's rating for the resume and where a gap can be closed.
 */
export function ResumeRanking({ ranking }: { ranking: ResumeRankingData }) {
    const [first, second] = ranking.resumes;

    return (
        <div className="space-y-3">
            <p className="type-body text-text-secondary">
                <span className="font-medium text-text-primary">
                    Recommended: {first.name}.
                </span>{" "}
                {reasonFor(first, second)}
            </p>
            {ranking.stale && (
                <p className="type-caption text-text-muted">
                    Your resumes changed since this ranking. Use “Refresh
                    evidence” on the analysis page to update it (no AI call).
                </p>
            )}
            <ol className="space-y-2">
                {ranking.resumes.map((resume, position) => (
                    <li
                        key={resume.ulid}
                        className="rounded-kraken-sm border border-border-subtle p-3"
                    >
                        <details>
                            <summary className="flex cursor-pointer flex-wrap items-center gap-2">
                                <span className="font-medium text-text-primary">
                                    {resume.name}
                                </span>
                                {position === 0 && <Badge>Recommended</Badge>}
                                <span className="type-caption text-text-muted tabular-nums">
                                    {resume.must.strong} of {total(resume.must)}{" "}
                                    must-haves strong
                                    {resume.must.partial > 0 &&
                                        `, ${resume.must.partial} partial`}
                                    {total(resume.nice) > 0 &&
                                        ` · ${resume.nice.strong} of ${total(resume.nice)} nice-to-haves strong`}
                                </span>
                            </summary>
                            <ul className="mt-3 space-y-2">
                                {resume.requirements.map((row) => {
                                    const badge = coverageBadge[row.coverage];

                                    return (
                                        <li
                                            key={`${row.kind}-${row.index}`}
                                            className="space-y-1 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant={badge.variant}>
                                                    <badge.icon aria-hidden />{" "}
                                                    {badge.label}
                                                </Badge>
                                                <span className="text-text-primary">
                                                    {row.requirement}
                                                </span>
                                                {row.kind === "nice" && (
                                                    <span className="type-caption text-text-muted">
                                                        nice to have
                                                    </span>
                                                )}
                                            </div>
                                            {row.evidence[0] && (
                                                <p className="type-caption text-text-secondary">
                                                    {row.evidence[0].text}
                                                </p>
                                            )}
                                            {row.missing_from === "library" && (
                                                <p className="type-caption text-text-secondary">
                                                    Better evidence is in your
                                                    library: add it while
                                                    tailoring.
                                                </p>
                                            )}
                                            {row.missing_from ===
                                                "experience" && (
                                                <p className="type-caption text-text-muted">
                                                    Not shown anywhere in your
                                                    library. Address it
                                                    honestly; do not invent it.
                                                </p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </details>
                    </li>
                ))}
            </ol>
        </div>
    );
}
