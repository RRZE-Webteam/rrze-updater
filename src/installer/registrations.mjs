export function registrationCandidates(job) {
    return Object.values(job.items).filter(item => item.status === 'ready' && item.plan?.action === 'register');
}
