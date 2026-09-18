const active = job => ['checking', 'running'].includes(job?.phase);

/** One request at a time; a pending cancellation always takes priority over work. */
export async function runJob(operation, context) {
    const { current, working, stop, cancelTarget, request, accept, setBusy, setError,
        setUncertain, setCancelling, setReview, connectors, connector, selected, messages } = context;
    if (working.current) return;
    working.current = true;
    stop.current = !!cancelTarget.current;
    setBusy(true); setError('');
    async function sync() {
        const result = await request('status');
        accept(result.job);
        setReview(!!result.job);
        return result.job;
    }
    async function persistCancellation() {
        if (current.current?.id !== cancelTarget.current) {
            // Another tab replaced the job. Never transfer cancellation to it.
            cancelTarget.current = null;
            throw new Error(messages.changed);
        }
        if (!['cancelled', 'complete'].includes(current.current.phase)) {
            const result = await request('cancel', { job: cancelTarget.current, revision: current.current.revision });
            accept(result.job);
        }
        cancelTarget.current = null;
    }
    try {
        if (operation === 'cancel' || cancelTarget.current) {
            setCancelling(true);
            await sync();
            await persistCancellation();
            setUncertain(false);
            return;
        }
        if (operation === 'resume') {
            await sync(); setUncertain(false);
        } else {
            const values = { job: current.current?.id || '', revision: current.current?.revision ?? -1 };
            if (operation === 'check') values.connectors = connectors;
            if (operation === 'check_custom') {
                values.connectors = { browse: connector };
                values.selection = selected.map(({ repository, branch, folder }) => ({ repository, branch, folder }));
            }
            const result = await request(operation, values);
            if (cancelTarget.current === values.job) cancelTarget.current = result.job.id;
            accept(result.job); setReview(true);
        }
        while (!stop.current && active(current.current)) {
            const result = await request('step', { job: current.current.id, revision: current.current.revision });
            accept(result.job);
        }
        if (cancelTarget.current) await persistCancellation();
    } catch (error) {
        stop.current = true; setUncertain(true);
        setError(error.message || messages.failed);
        try {
            await sync();
            if (cancelTarget.current) {
                await persistCancellation();
                setError('');
            }
            setUncertain(false);
        } catch (recoveryError) {
            // Keep cancellation intent until acknowledged. A retry must not step.
            setError(cancelTarget.current ? messages.cancelPending : recoveryError.message || messages.failed);
        }
    } finally {
        working.current = false;
        setCancelling(false); setBusy(false);
    }
}
