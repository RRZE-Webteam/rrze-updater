(function (root) {
    'use strict';

    // Keep the in-flight request alive: its server-side result must finish saving
    // before the dialog reports a stopped process or offers Resume.
    root.rrzeUpdaterCreateCheckRunner = function (options) {
        var completed = 0;
        var status = 'idle';
        var inFlight = false;
        var timer = null;
        var schedule = options.setTimeout || root.setTimeout.bind(root);
        var unschedule = options.clearTimeout || root.clearTimeout.bind(root);

        function notify() {
            options.onChange({ status: status, completed: completed, total: options.total });
        }

        async function step() {
            timer = null;
            if (status !== 'running') return;
            inFlight = true;
            try {
                await options.check(completed);
            } catch (error) {
                if (options.onError) options.onError(error, completed);
            } finally {
                inFlight = false;
                completed++;
                if (completed >= options.total) {
                    status = 'complete';
                } else if (status === 'stopping') {
                    status = 'stopped';
                } else {
                    timer = schedule(step, options.delay);
                }
                notify();
            }
        }

        return {
            resume: function () {
                if (status !== 'idle' && status !== 'stopped') return;
                status = completed >= options.total ? 'complete' : 'running';
                notify();
                if (status === 'running') step();
            },
            stop: function () {
                if (status !== 'running') return;
                if (timer !== null) {
                    unschedule(timer);
                    timer = null;
                }
                status = inFlight ? 'stopping' : 'stopped';
                notify();
            }
        };
    };
}(window));
