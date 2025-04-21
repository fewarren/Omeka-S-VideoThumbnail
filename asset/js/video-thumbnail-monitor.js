/**
 * Video Thumbnail Job Monitor
 */
class VideoThumbnailMonitor {
    constructor(options = {}) {
        this.options = Object.assign({
            pollInterval: 5000,
            containerSelector: '.video-thumbnail-jobs',
            jobTemplateSelector: '#job-status-template',
            notificationDuration: 5000
        }, options);

        this.container = document.querySelector(this.options.containerSelector);
        this.jobTemplate = document.querySelector(this.options.jobTemplateSelector);
        this.activeJobs = new Map();
        this.init();
    }

    init() {
        this.initializeJobStatuses();
        this.startPolling();
        this.bindEventListeners();
    }

    initializeJobStatuses() {
        const jobElements = this.container.querySelectorAll('.job-status');
        jobElements.forEach(element => {
            const jobId = element.dataset.jobId;
            if (jobId) {
                this.activeJobs.set(jobId, {
                    element,
                    status: element.dataset.status,
                    progress: parseInt(element.dataset.progress, 10) || 0
                });
            }
        });
    }

    startPolling() {
        this.pollInterval = setInterval(() => this.pollJobStatuses(), this.options.pollInterval);
    }

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }

    async pollJobStatuses() {
        try {
            const response = await fetch('/admin/video-thumbnail/job-status', {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to fetch job statuses');
            }

            const statuses = await response.json();
            this.updateJobStatuses(statuses);

        } catch (error) {
            console.error('Error polling job statuses:', error);
            this.showNotification('Error checking job status', 'error');
        }
    }

    updateJobStatuses(statuses) {
        Object.entries(statuses).forEach(([jobId, status]) => {
            const jobInfo = this.activeJobs.get(jobId);

            if (jobInfo) {
                this.updateJobUI(jobId, status, jobInfo.element);
            } else if (status.status === 'running' || status.status === 'retrying') {
                this.createJobElement(jobId, status);
            }

            // Clean up completed jobs after a delay
            if (status.status === 'complete' || status.status === 'error') {
                setTimeout(() => {
                    this.removeJob(jobId);
                }, this.options.notificationDuration);
            }
        });
    }

    updateJobUI(jobId, status, element) {
        // Update status class
        element.className = `job-status ${status.status}`;
        
        // Update progress bar
        const progressBar = element.querySelector('.progress');
        if (progressBar) {
            progressBar.style.width = `${status.progress}%`;
            progressBar.setAttribute('aria-valuenow', status.progress);
            progressBar.setAttribute('aria-valuemin', '0');
            progressBar.setAttribute('aria-valuemax', '100');
            progressBar.setAttribute('role', 'progressbar');
        }

        // Update status text
        const statusText = element.querySelector('.status-text');
        if (statusText) {
            statusText.textContent = this.formatStatus(status);
            statusText.setAttribute('aria-live', 'polite');
        }

        // Show retry information if applicable
        const retryInfo = element.querySelector('.retry-info');
        if (retryInfo) {
            if (status.retryCount) {
                retryInfo.textContent = `Retry ${status.retryCount} of ${status.maxRetries}`;
                retryInfo.style.display = 'block';
            } else {
                retryInfo.style.display = 'none';
            }
        }

        // Show error message if applicable
        if (status.error) {
            this.showNotification(status.error, 'error');
        }
    }

    createJobElement(jobId, status) {
        if (!this.jobTemplate) {
            console.error('Job template not found');
            return;
        }

        const jobElement = this.jobTemplate.content.cloneNode(true).firstElementChild;
        jobElement.dataset.jobId = jobId;
        
        this.activeJobs.set(jobId, {
            element: jobElement,
            status: status.status,
            progress: status.progress
        });

        this.updateJobUI(jobId, status, jobElement);
        this.container.appendChild(jobElement);
    }

    removeJob(jobId) {
        const jobInfo = this.activeJobs.get(jobId);
        if (jobInfo && jobInfo.element) {
            jobInfo.element.remove();
            this.activeJobs.delete(jobId);
        }
    }

    formatStatus(status) {
        const statusMap = {
            'running': 'Processing',
            'retrying': 'Retrying',
            'complete': 'Complete',
            'error': 'Failed',
            'stopped': 'Stopped'
        };

        return statusMap[status.status] || 'Unknown';
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.setAttribute('role', 'alert');
        notification.setAttribute('aria-live', 'assertive');
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, this.options.notificationDuration);
    }

    bindEventListeners() {
        // Handle manual retry requests
        this.container.addEventListener('click', async (event) => {
            const retryButton = event.target.closest('.retry-button');
            if (retryButton) {
                const jobId = retryButton.closest('.job-status').dataset.jobId;
                await this.retryJob(jobId);
            }
        });

        // Handle manual stop requests
        this.container.addEventListener('click', async (event) => {
            const stopButton = event.target.closest('.stop-button');
            if (stopButton) {
                const jobId = stopButton.closest('.job-status').dataset.jobId;
                await this.stopJob(jobId);
            }
        });
    }

    async retryJob(jobId) {
        try {
            const response = await fetch(`/admin/video-thumbnail/retry-job/${jobId}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to retry job');
            }

            this.showNotification('Job retry initiated');

        } catch (error) {
            console.error('Error retrying job:', error);
            this.showNotification('Failed to retry job', 'error');
        }
    }

    async stopJob(jobId) {
        try {
            const response = await fetch(`/admin/video-thumbnail/stop-job/${jobId}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to stop job');
            }

            this.showNotification('Job stopped successfully');

        } catch (error) {
            console.error('Error stopping job:', error);
            this.showNotification('Failed to stop job', 'error');
        }
    }
}

// Initialize the monitor when the DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const monitor = new VideoThumbnailMonitor({
        pollInterval: window.videoThumbnailConfig?.pollInterval || 5000
    });
});