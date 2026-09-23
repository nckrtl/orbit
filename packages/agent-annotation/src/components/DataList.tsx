import type { ReactNode } from "react";

export default function DataList({ children }: { children: ReactNode }) {
    return <dl className="divide-y divide-white/10 text-[0.7rem]">{children}</dl>;
}
