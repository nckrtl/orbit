import { QueryClient } from "@tanstack/react-query";

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            // Realtime events keep the lists current; a refetch is the fallback, not the clock.
            staleTime: 30_000,
            retry: 1,
        },
    },
});
