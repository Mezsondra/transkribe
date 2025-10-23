/**
 * Transcribe AI - File Uploader Script with Language Support - FIXED VERSION
 * Version: 15.2
 */

(function($) {
    'use strict';

    class TranscribeUploader {
        constructor() {
            this.config = window.transcribeAI || {};
            this.currentFile = null;
            this.pollInterval = null;
            this.jobId = null;
            this.selectedLanguage = 'en';
            
            this.init();
        }

        init() {
            this.cacheElements();
            this.bindEvents();
            this.updateUserStatus();
        }

        cacheElements() {
            this.$container = $('#transcribe-ai-container');
            this.$dropZone = $('#drop-zone');
            this.$fileInput = $('#audioFile');
            this.$fileInfo = $('#file-info');
            this.$languageSelection = $('#language-selection');
            this.$languageSelect = $('#language-select');
            this.$transcribeBtn = $('#transcribeBtn');
            this.$progressContainer = $('#progress-container');
            this.$userStatus = $('#user-status-bar');
        }

        bindEvents() {
            // File input
            this.$fileInput.on('change', (e) => this.handleFileSelect(e));
            
            // Drop zone
            this.$dropZone.on('click', (e) => {
                e.preventDefault();
                this.$fileInput.click();
            });
            
            this.$dropZone.on('dragover', (e) => this.handleDragOver(e));
            this.$dropZone.on('dragleave', (e) => this.handleDragLeave(e));
            this.$dropZone.on('drop', (e) => this.handleDrop(e));
            
            // Language selection
            this.$languageSelect.on('change', (e) => {
                this.selectedLanguage = e.target.value;
            });
            
            // Transcribe button
            this.$transcribeBtn.on('click', () => this.startTranscription());
            
            // Prevent default drag behaviors on document
            $(document).on('dragover drop', (e) => {
                if (!$(e.target).closest('#drop-zone').length) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        handleFileSelect(e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                this.setFile(files[0]);
            }
        }

        handleDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            this.$dropZone.addClass('drag-over');
        }

        handleDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            this.$dropZone.removeClass('drag-over');
        }

        handleDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            this.$dropZone.removeClass('drag-over');
            
            const files = e.originalEvent.dataTransfer.files;
            if (files && files.length > 0) {
                this.setFile(files[0]);
            }
        }

        setFile(file) {
            // Validate file type
            const validExtensions = ['mp3', 'wav', 'm4a', 'mp4', 'ogg', 'webm', 'aac', 'flac'];
            const fileExtension = file.name.split('.').pop().toLowerCase();
            
            if (!validExtensions.includes(fileExtension)) {
                this.showNotification('Please select a valid audio or video file (MP3, WAV, M4A, MP4, OGG, WebM)', 'error');
                return;
            }
            
            // Check file size (max 500MB)
            const maxSize = 500 * 1024 * 1024;
            if (file.size > maxSize) {
                this.showNotification('File size must be less than 500MB', 'error');
                return;
            }
            
            this.currentFile = file;
            this.displayFileInfo();
        }

        displayFileInfo() {
            if (!this.currentFile) return;
            
            const fileSize = this.formatFileSize(this.currentFile.size);
            const fileName = this.escapeHtml(this.currentFile.name);
            
            const infoHtml = `
                <div class="file-info-content">
                    <div class="file-icon">
                        <span class="material-symbols-outlined">audio_file</span>
                    </div>
                    <div class="file-details">
                        <p class="file-name">${fileName}</p>
                        <p class="file-meta">Size: ${fileSize}</p>
                    </div>
                    <button class="remove-file" type="button">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            `;
            
            this.$fileInfo.html(infoHtml).show();
            
            // Bind remove button
            this.$fileInfo.find('.remove-file').on('click', () => this.removeFile());
            
            // Show language selection and transcribe button
            this.$languageSelection.slideDown();
            $('#transcribe-controls').slideDown();
            this.$transcribeBtn.prop('disabled', false);
        }

        removeFile() {
            this.currentFile = null;
            this.$fileInput.val('');
            this.$fileInfo.hide().empty();
            this.$languageSelection.slideUp();
            $('#transcribe-controls').slideUp();
            this.$transcribeBtn.prop('disabled', true);
        }

        startTranscription() {
            if (!this.currentFile) {
                this.showNotification('Please select a file first', 'error');
                return;
            }
            
            // Check user status
            const userData = this.config.user_data;
            if (!userData) {
                this.showNotification('User data not available. Please refresh the page.', 'error');
                return;
            }
            
            // Check usage limits
            if (userData.minutes_remaining !== 'unlimited' && userData.minutes_remaining <= 0) {
                let message;
                if (userData.role === 'guest') {
                    message = 'You have reached your 20-minute monthly guest limit. Please log in for more minutes or wait until next month.';
                } else if (userData.role === 'basic') {
                    message = 'You have reached your 120-minute monthly limit. Please upgrade to Premium for unlimited transcriptions.';
                } else {
                    message = 'You have reached your monthly limit.';
                }
                this.showNotification(message, 'error');
                return;
            }
            
            // Get selected language
            this.selectedLanguage = this.$languageSelect.val() || 'en';
            
            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'start_transcription');
            formData.append('audio_file', this.currentFile);
            formData.append('language', this.selectedLanguage);
            formData.append('nonce', this.config.nonce);
            
            // Update UI
            this.$transcribeBtn.prop('disabled', true).text('Uploading...');
            this.showProgress(0, 'Uploading file...');
            
            // Disable language selection during upload
            this.$languageSelect.prop('disabled', true);
            
            // Start upload
            $.ajax({
                url: this.config.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: () => {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable && e.total > 0) {
                            const percentComplete = (e.loaded / e.total) * 100;
                            this.showProgress(percentComplete * 0.3, 'Uploading... ' + Math.round(percentComplete) + '%');
                        } else {
                            // Fallback for indeterminate progress
                            this.showProgress(15, 'Uploading...');
                        }
                    }, false);
                    return xhr;
                },
                success: (response) => {
                    if (response.success) {
                        this.jobId = response.data.job_id;
                        this.showProgress(30, 'Upload complete. Starting transcription...');
                        
                        // Show language info
                        const languageName = this.config.languages ? this.config.languages[this.selectedLanguage] : this.selectedLanguage;
                        this.showProgress(35, `Processing ${languageName} audio...`);
                        
                        this.startPolling();
                    } else {
                        this.handleError(response.data || 'Upload failed');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Upload error:', xhr, status, error);
                    let errorMessage = 'Network error: ';
                    
                    // Enhanced error handling
                    if (xhr.status === 0) {
                        errorMessage += 'Connection failed. Please check your internet connection.';
                    } else if (xhr.status >= 500) {
                        errorMessage += 'Server error. Please try again later.';
                    } else if (xhr.status === 413) {
                        errorMessage += 'File too large. Please select a smaller file.';
                    } else {
                        errorMessage += error || 'Connection failed';
                    }
                    
                    this.handleError(errorMessage);
                }
            });
        }

        startPolling() {
            let pollCount = 0;
            const maxPolls = 120; // 10 minutes max
            
            this.pollInterval = setInterval(() => {
                pollCount++;
                
                if (pollCount > maxPolls) {
                    this.stopPolling();
                    this.handleError('Transcription timeout. Please try again.');
                    return;
                }
                
                $.ajax({
                    url: this.config.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'check_transcription',
                        job_id: this.jobId,
                        nonce: this.config.nonce
                    },
                    success: (response) => {
                        if (response.success) {
                            const status = response.data.status;
                            
                            if (status === 'completed') {
                                this.stopPolling();
                                this.showProgress(100, 'Transcription complete! Redirecting...');
                                
                                // Show success notification for guest users
                                const userData = this.config.user_data;
                                if (!userData.is_logged_in) {
                                    this.showNotification('Transcription complete! Note: As a guest, this transcript is temporary. Log in to save your transcripts.', 'info');
                                }
                                
                                setTimeout(() => {
                                    window.location.href = response.data.redirect_url;
                                }, 2000);
                                
                            } else if (status === 'processing' || status === 'queued') {
                                // FIX: Use real progress from API if available, otherwise fall back to estimation.
                                let progress = 30; // Base progress after upload
                                if (response.data.progress_percent !== undefined && response.data.progress_percent !== null) {
                                    // AssemblyAI provides progress_percent, use it. (API response structure may vary)
                                    // We'll scale it to be between 30% and 95%.
                                    progress = 30 + (response.data.progress_percent * 0.65);
                                } else {
                                    // Fallback to the estimated progress
                                    progress = 30 + (pollCount * 2);
                                }
                                progress = Math.min(progress, 95); // Cap at 95%

                                const languageName = this.config.languages ? this.config.languages[this.selectedLanguage] : this.selectedLanguage;
                                this.showProgress(progress, `Processing ${languageName} transcript... (${status})`);

                            } else if (status === 'error') {
                                this.stopPolling();
                                this.handleError('Transcription failed. Please try again.');
                            }
                        } else {
                            console.warn('Status check failed:', response.data);
                            if (pollCount > 3) {
                                this.stopPolling();
                                this.handleError(response.data || 'Status check failed');
                            }
                        }
                    },
                    error: (xhr, status, error) => {
                        console.warn('Poll failed, retrying...', error);
                        // Don't stop polling immediately on network errors
                        // Only stop if we've had too many consecutive failures
                        if (pollCount > maxPolls * 0.8) { // Stop if we're near the end and getting errors
                            this.stopPolling();
                            this.handleError('Network error during transcription. Please try again.');
                        }
                    }
                });
            }, 5000);
        }

        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        }

        showProgress(percent, text) {
            if (!this.$progressContainer.is(':visible')) {
                this.$progressContainer.show();
            }
            
            // Ensure percent is within bounds
            percent = Math.max(0, Math.min(100, percent));
            
            const progressHtml = `
                <div class="progress-wrapper">
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: ${percent}%"></div>
                    </div>
                    <p class="progress-text">${this.escapeHtml(text)}</p>
                </div>
            `;
            
            this.$progressContainer.html(progressHtml);
        }

        hideProgress() {
            this.$progressContainer.hide().empty();
        }

        handleError(message) {
            this.stopPolling();
            this.hideProgress();
            this.$transcribeBtn.prop('disabled', false).text('Start Transcription');
            this.$languageSelect.prop('disabled', false);
            this.showNotification(message, 'error');
        }

        updateUserStatus() {
            const userData = this.config.user_data;
            
            if (!userData) {
                console.warn('User data not available');
                return;
            }
            
            // Update user status display (already handled by PHP template)
            // But we can add dynamic updates here if needed
            
            // If guest with no minutes left, show upgrade prompt
            if (userData.role === 'guest' && userData.minutes_remaining === 0) {
                this.showNotification('You have used all your free guest minutes this month. Please log in or wait until next month.', 'warning');
            }
        }

        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return (text || '').toString().replace(/[&<>"']/g, m => map[m]);
        }

        showNotification(message, type = 'info') {
            const typeClasses = {
                success: 'notification-success',
                error: 'notification-error',
                info: 'notification-info',
                warning: 'notification-warning'
            };

            // Validate type to prevent XSS
            const validTypes = ['success', 'error', 'info', 'warning'];
            const safeType = validTypes.includes(type) ? type : 'info';

            // Remove existing notifications
            $('.notification').remove();

            const escapedMessage = this.escapeHtml(message);

            const $notification = $(`
                <div class="notification ${typeClasses[safeType]}">
                    <span class="notification-message">${escapedMessage}</span>
                    <button class="notification-close" type="button">&times;</button>
                </div>
            `);
            
            $('body').append($notification);
            
            // Animate in
            setTimeout(() => $notification.addClass('show'), 10);
            
            // Close button
            $notification.find('.notification-close').on('click', () => {
                $notification.removeClass('show');
                setTimeout(() => $notification.remove(), 300);
            });
            
            // Auto close after 7 seconds for non-error messages
            if (type !== 'error') {
                setTimeout(() => {
                    $notification.removeClass('show');
                    setTimeout(() => $notification.remove(), 300);
                }, 7000);
            }
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        if ($('#transcribe-ai-container').length > 0) {
            window.transcribeUploader = new TranscribeUploader();
        }
    });

})(jQuery);