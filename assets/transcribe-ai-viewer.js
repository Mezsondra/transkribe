/**
 * Transcribe AI - Transcript Viewer Script
 * Version: 19.0 - WITH ALL REQUESTED FIXES
 * 
 * FIXES APPLIED:
 * - Better search function with proper highlighting
 * - Speaker names can be fully edited without "Speaker" prefix
 * - Changed speaker names appear in translations
 * - Export uses transcript title as filename
 */

(function($) {
    'use strict';

    const CONSTANTS = {
        AUTOSAVE_CHECK_INTERVAL: window.transcriptViewer?.autosave_interval || 120000,
        SEARCH_DEBOUNCE_MS: 300,
        NOTIFICATION_TIMEOUT: 5000,
        NOTIFICATION_TIMEOUT_ERROR: 10000,
        STICKY_OFFSET: 100,
        MAX_SEARCH_RESULTS: 500
    };
    
    const DEBUG = false;
    const log = DEBUG ? console.log.bind(console) : () => {};
    const warn = DEBUG ? console.warn.bind(console) : () => {};

    class TranscriptViewer {
        constructor() {
            this.config = window.transcriptViewer || {};
            this.transcriptId = this.config.transcript_id;
            this.ajaxUrl = this.config.ajax_url;
            this.nonce = this.config.nonce;
            this.transcriptData = null;
            this.isEditing = false;
            this.audioPlayer = null;
            this.currentUtterance = -1;
            this.highlights = [];
            this.highlightMode = false;
            this.currentTimestampMode = 'utterance';
            this.selectedText = '';
            this.speakerMap = {};
            this.searchResults = [];
            this.currentSearchIndex = 0;
            this.hasUnsavedChanges = false;
            this.searchDebounce = null;
            this.eventHandlers = new Map();
            this.stickyResizeHandler = null;
            this.isSaving = false;
            this.saveQueue = [];
            this.searchIndex = null;
            this.undoStack = [];
            this.MAX_UNDO_STACK = 20;
            this.loadingTimer = null;
            this.dynamicHighlightSpan = null;
            this.isSelectingText = false; // **NEW FLAG**
            this.init();
        }

        init() {
            log('TranscriptViewer initializing...');
            this.cacheElements();
            this.bindEvents();
            this.loadTranscript();
            
            if (window.location.hash === '#export') {
                setTimeout(() => this.showExportModal(), 500);
            }
            
            this.autoSaveInterval = setInterval(() => {
                if (this.hasUnsavedChanges && this.isEditing && !this.isSaving) {
                    this.saveTranscript(false);
                }
            }, CONSTANTS.AUTOSAVE_CHECK_INTERVAL);
        }
        
        cacheElements() {
            // Audio Player
            this.$audioPlayer = $('#audioPlayer');
            this.audioPlayer = this.$audioPlayer[0];
            this.$playBtn = $('#playPauseBtn');
            this.$progressBar = $('#progressBar');
            this.$currentTime = $('#currentTime');
            this.$totalTime = $('#totalTime');
            this.$rewindBtn = $('#rewindBtn');
            this.$forwardBtn = $('#forwardBtn');
            this.$speedBtn = $('#speedBtn');
            
            // Transcript Elements
            this.$transcriptContent = $('#transcript-content');
            this.$title = $('#transcript-title');
            this.$date = $('#transcript-date');
            
            // Control Buttons
            this.$editBtn = $('#editBtn');
            this.$exportBtn = $('#exportBtn');
            this.$deleteBtn = $('#deleteBtn');
            this.$summaryBtn = $('#summaryBtn');
            this.$highlightBtn = $('#highlightBtn');
            
            // Translation
            this.$translateContainer = $('#translate-container');
            this.$translateDropdownBtn = $('#translateDropdownBtn');
            this.$translateLanguageList = $('#translate-language-list');
            this.$translationWrapper = $('#translation-content-wrapper');
            this.$translationContent = $('#translation-content');
            this.$closeTranslationBtn = $('#closeTranslationBtn');
            
            // Timestamps
            this.$timestampsDropdown = $('#timestampsDropdown');
            this.$timestampsBtn = $('#timestampsBtn');
            this.$timestampsMenu = $('#timestampsMenu');
            this.$timestampsBtnLabel = $('#timestampsBtnLabel');
            
            // Layout
            this.$viewerContainer = $('.transcribe-ai-viewer');
            this.$contentArea = $('#transcript-area-container');
            
            // Summary & Highlights
            this.$summaryBox = $('#summaryBox');
            this.$highlightBox = $('#highlightBox');
            
            // Search Elements
            this.$searchWrapper = $('#searchWrapper');
            this.$searchBtn = $('#searchBtn');
            this.$searchBox = $('#searchBox');
            this.$searchInput = $('#searchInput');
            this.$searchCounter = $('#searchCounter');
            this.$searchPrev = $('#searchPrev');
            this.$searchNext = $('#searchNext');
            this.$replaceInput = $('#replaceInput');
            this.$replaceAllBtn = $('#replaceAllBtn');
            this.$replaceContainer = $('#replaceContainer');
            this.$searchOptionsBtn = $('#searchOptionsBtn');
            this.$searchOptionsMenu = $('#searchOptionsMenu');

            // Copy Controls
            this.$copyDropdown = $('#copyDropdown');
            this.$copyBtn = $('#copyTranscriptBtn');
            this.$copyMenu = $('#copyMenu');
            this.$copyTranslationOption = this.$copyMenu.find('.translation-option');

            this.updateCopyButtonState();
        }

rgbToHex(rgb) {
    if (!rgb || !rgb.startsWith('rgb')) return '#ffeb3b'; // Default color
    const colors = rgb.match(/\d+/g).map(Number);
    const toHex = (c) => ('0' + c.toString(16)).slice(-2);
    return `#${toHex(colors[0])}${toHex(colors[1])}${toHex(colors[2])}`;
}

        bindEvents() {
            // Audio Controls
            this.$playBtn.on('click', () => this.togglePlayPause());
            this.$rewindBtn.on('click', () => this.skip(-10));
            this.$forwardBtn.on('click', () => this.skip(10));
            this.$speedBtn.on('click', () => this.cycleSpeed());
            this.$progressBar.on('input', e => this.seekTo(e.target.value));

            if (this.audioPlayer) {
                this.audioPlayer.addEventListener('loadedmetadata', () => this.onAudioLoaded());
                this.audioPlayer.addEventListener('timeupdate', () => this.onTimeUpdate());
                this.audioPlayer.addEventListener('play', () => this.onPlay());
                this.audioPlayer.addEventListener('pause', () => this.onPause());
                this.audioPlayer.addEventListener('ended', () => this.onEnded());
                this.audioPlayer.addEventListener('error', (e) => this.onAudioError(e));
            } else {
                warn('Audio player element not found');
            }
            
            // Control Buttons
            this.$editBtn.on('click', () => this.toggleEdit());
            this.$exportBtn.on('click', () => this.showExportModal());
            this.$deleteBtn.on('click', () => this.confirmDelete());
            this.$summaryBtn.on('click', () => this.toggleSummary());
            this.$highlightBtn.on('click', () => this.toggleHighlightMode());
            
            // Search
            this.$searchBtn.on('click', (e) => {
                e.stopPropagation();
                this.toggleSearch();
            });
            
            this.$searchInput.on('input', e => {
                clearTimeout(this.searchDebounce);
                this.searchDebounce = setTimeout(() => {
                    this.performSearch(e.target.value);
                }, CONSTANTS.SEARCH_DEBOUNCE_MS);
            });
            
            this.$searchPrev.on('click', () => this.navigateSearch('prev'));
            this.$searchNext.on('click', () => this.navigateSearch('next'));
            this.$searchInput.on('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.navigateSearch(e.shiftKey ? 'prev' : 'next');
                } else if (e.key === 'Escape') {
                    this.toggleSearch();
                }
            });
            
            // Search Options
            this.$searchOptionsBtn.on('click', (e) => {
                e.stopPropagation();
                this.$searchOptionsMenu.toggleClass('open');
            });
            
            this.$searchOptionsMenu.on('click', '.search-option', (e) => {
                const $option = $(e.currentTarget);
                const optionName = $option.data('option');
                $option.toggleClass('active');
                const isActive = $option.hasClass('active');
                $option.find('.material-symbols-outlined').text(
                    isActive ? 'check_box' : 'check_box_outline_blank'
                );
                if (optionName === 'findAndReplace') {
                    this.$replaceContainer.toggle(isActive);
                }
                clearTimeout(this.searchDebounce);
                this.performSearch(this.$searchInput.val());
            });
            
            // Replace
            this.$replaceAllBtn.on('click', () => this.performReplaceAll());
            
            // Timestamps
            this.$timestampsDropdown.on('click', '#timestampsBtn', (e) => {
                e.stopPropagation();
                this.$timestampsMenu.toggleClass('open');
                $(e.currentTarget).attr('aria-expanded', this.$timestampsMenu.hasClass('open'));
            });
            
            this.$timestampsMenu.on('click', '.timestamps-option', (e) => {
                const mode = $(e.currentTarget).data('mode');
                this.setTimestampView(mode);
            });

            // Translation
            this.$translateDropdownBtn.on('click', (e) => {
                e.stopPropagation();
                this.$translateLanguageList.toggleClass('open');
            });
            
            const languageSelectionHandler = (e) => this.handleLanguageSelection(e);
            this.$translateLanguageList.on('click', '.translate-language-item', languageSelectionHandler);
            this.eventHandlers.set('language-selection', {
                element: this.$translateLanguageList,
                event: 'click',
                selector: '.translate-language-item',
                handler: languageSelectionHandler
            });

            this.$closeTranslationBtn.on('click', () => this.hideTranslation());

            // Copy controls
            if (this.$copyBtn && this.$copyBtn.length) {
                this.$copyBtn.on('click', (e) => this.handleCopyButtonClick(e));
                this.$copyBtn.on('keydown', (e) => {
                    if (!this.isTranslationVisible()) return;
                    if (e.key === 'ArrowDown' || (e.key === 'Enter' && e.altKey)) {
                        e.preventDefault();
                        this.toggleCopyMenu(true);
                    }
                });
            }

            if (this.$copyMenu && this.$copyMenu.length) {
                this.$copyMenu.on('click', '.copy-option', (e) => this.handleCopyOptionClick(e));
            }

            // Global clicks
            $(document).on('click', (e) => {
                if (!$(e.target).closest('.control-dropdown-container').length) {
                    $('.control-dropdown-menu.open').removeClass('open');
                    $('.control-btn[aria-expanded="true"]').attr('aria-expanded', false);
                    this.updateCopyButtonState();
                }
                if (!$(e.target).closest('.highlight-popup').length &&
                    !$(e.target).closest('.utterance-text').length) {
                    $('.highlight-popup').remove();
                }
            });

            // Title & Speaker editing
            this.$title.on('click', () => this.enableTitleEdit());
            this.$title.on('blur keydown', (e) => this.handleTitleEdit(e));
            this.$transcriptContent.on('click', '.speaker-label', (e) => this.enableSpeakerEdit(e));
            this.$transcriptContent.on('blur keydown', '.speaker-label[contenteditable="true"]', 
                (e) => this.handleSpeakerEdit(e));
            
            this.$transcriptContent.on('input', '.utterance-text[contenteditable="true"]', () => {
                this.hasUnsavedChanges = true;
            });
            
            // Highlights
         // Highlights - UPDATE THIS SECTION
this.$transcriptContent.on('mousedown', '.utterance-text', (e) => {
    if (this.highlightMode) {
        this.isSelectingText = true;
        // Pause audio while selecting
        if (this.audioPlayer && !this.audioPlayer.paused) {
            this.audioPlayer.pause();
        }
    }
});

this.$transcriptContent.on('mouseup', '.utterance-text', (e) => {
    if (this.highlightMode) {
        e.stopPropagation();
        setTimeout(() => {
            this.handleTextSelection(e);
            this.isSelectingText = false;
        }, 10);
    }
});

// Also handle when mouse leaves while selecting
this.$transcriptContent.on('mouseleave', '.utterance-text', () => {
    if (this.highlightMode && this.isSelectingText) {
        setTimeout(() => {
            this.isSelectingText = false;
        }, 100);
    }
});            
            this.$transcriptContent.on('click', '[data-highlight-id]', (e) => {
                e.stopPropagation();
                const highlightId = $(e.currentTarget).data('highlight-id');
                this.showHighlightNote(highlightId);
            });
            
            // Word clicks for seeking// HANDLER 1: Fast and simple, for original un-edited text.
this.$transcriptContent.on('click', '.word', e => {
    // This handles clicks only on the original .word spans.
    if (this.isEditing || this.highlightMode) return;

    e.stopPropagation(); // Prevents the handler below from also firing.
    const startTime = $(e.currentTarget).data('start');
    if (startTime !== undefined) {
        this.seekTo(startTime / 1000);
    }
});

// HANDLER 2: Smart and robust, for edited or highlighted text blocks.
this.$transcriptContent.on('click', '.utterance-text', e => {
    // This handler activates for any click on the text block,
    // but we ignore it if a .word span was clicked directly (handled above).
    if (this.isEditing || this.highlightMode || $(e.target).hasClass('word')) {
        return;
    }

    const utteranceRow = e.currentTarget.closest('.utterance-row');
    if (!utteranceRow) return;

    const utteranceIndex = $(utteranceRow).data('index');
    const utteranceData = this.transcriptData.data.utterances[utteranceIndex];

    // This logic requires the preserved 'words' array with original timing.
    if (!utteranceData.words || utteranceData.words.length === 0) {
        this.seekTo($(utteranceRow).data('start') / 1000); // Fallback
        return;
    }

    // Get the character offset of the click within the entire utterance text element.
    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    
    let clickedCharOffset = range.startOffset;
    let container = range.startContainer;
    while (container !== e.currentTarget) {
        let sibling = container;
        while ((sibling = sibling.previousSibling) !== null) {
            clickedCharOffset += sibling.textContent.length;
        }
        container = container.parentNode;
    }

    // Find which word from our original timing data corresponds to that character offset.
    let runningCharCount = 0;
    let targetWord = null;

    for (const word of utteranceData.words) {
        // We find the word that contains the character offset of the click.
        const wordEndPosition = runningCharCount + word.text.length;
        if (clickedCharOffset >= runningCharCount && clickedCharOffset <= wordEndPosition) {
            targetWord = word;
            break;
        }
        runningCharCount += (word.text.length + 1); // +1 for the space
    }
    
    // If we couldn't find a direct match (e.g., click was on a space),
    // fall back to the last word before the click.
    if (!targetWord) {
        runningCharCount = 0;
        for (const word of utteranceData.words) {
            if(runningCharCount >= clickedCharOffset) break;
            targetWord = word;
            runningCharCount += (word.text.length + 1);
        }
    }

    if (targetWord) {
        this.seekTo(targetWord.start / 1000);
    }
});

// Add click-to-seek for the translation panel
            this.$translationContent.on('click', '.utterance-row', e => {
                // Clicks on translation rows should seek the audio player
                if (this.isEditing) return;
                const startTime = $(e.currentTarget).data('start');
                if (startTime !== undefined) {
                    this.seekTo(startTime / 1000);
                }
            });
            // Keyboard shortcuts
            $(document).on('keydown', e => this.handleKeyboard(e));
            
            // Modal close
            $('body').on('click', '.modal-close', function() {
                $(this).closest('.modal').fadeOut(200, function() {
                    $(this).remove();
                });
            });
            
            $(window).on('beforeunload', (e) => {
                if (this.hasUnsavedChanges) {
                    e.preventDefault();
                    return 'You have unsaved changes. Are you sure you want to leave?';
                }
            });
        // --- START: CORRECTED STICKY HEADER FIX ---
            
            const $stickyEl = this.$viewerContainer.find('.sticky-container');

            if ($stickyEl.length) {
                // Get the original top position of the sticky element
                const stickyTop = $stickyEl.offset().top;

                // Use a throttled scroll handler for better performance
                let isThrottled = false;
                
                // Listen to the main window for scrolling
                $(window).on('scroll', () => {
                    if (isThrottled) return;
                    isThrottled = true;
                    
                    setTimeout(() => {
                        // Get the window's scroll position
                        const scrollTop = $(window).scrollTop(); 
                        
                        // Check if we have scrolled past the header's original position
                        if (scrollTop > stickyTop) {
                            $stickyEl.addClass('is-sticky');
                        } else {
                            $stickyEl.removeClass('is-sticky');
                        }
                        isThrottled = false;
                    }, 100); // Throttle to run every 100ms
                });
            }
            
            // --- END: CORRECTED STICKY HEADER FIX ---
        }
// Bindevents ENDS
        onAudioError(e) {
            console.error('Audio error:', e);
            this.showNotification('Audio failed to load. Please refresh the page.', 'error');
        }
        
// In transcribe-ai-viewer.js, inside the TranscriptViewer class

// In transcribe-ai-viewer.js, add this function inside the TranscriptViewer class



        // ==========================================
        // TRANSCRIPT LOADING
        // ==========================================

        loadTranscript() {
            if (!this.transcriptId) {
                return this.showError('No transcript ID provided');
            }
            
            // this.showLoading(true);
            
            $.post(this.ajaxUrl, {
                action: 'get_transcript_data',
                transcript_id: this.transcriptId,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    this.transcriptData = res.data;
                    this.speakerMap = res.data.speaker_map || {};
                    
                    if (!this.transcriptData.data || !this.transcriptData.data.utterances) {
                        throw new Error('Invalid transcript data structure');
                    }
                    
                    
                    try {
                        this.renderTranscript();
                        this.setupTranslation();
                        this.initializeAudio();
                        this.loadHighlights();
                        this.buildSearchIndex();
                        
                        if (res.data.summary) {
                            this.displaySummary(res.data.summary, res.data.chapters);
                        }
                    } catch (error) {
                        console.error('Render error:', error);
                        this.showError('Failed to render transcript: ' + error.message);
                    }
                } else {
                    this.showError(res.data || 'Failed to load transcript');
                }
            })
            .fail(() => this.showError('Network error.'))
       //     .always(() => this.showLoading(false));
        }

        renderTranscript() {
            this.$title.text(this.transcriptData.title);
            this.$date.text(this.transcriptData.date);

            const utterances = this.transcriptData.data.utterances || [];
            if (utterances.length === 0) {
                this.$transcriptContent.html('<p>No transcript content available.</p>');
                return;
            }

            const speakerColors = this.generateSpeakerColors(utterances);
            let html = '';
            
            utterances.forEach((utterance, index) => {
               // This is the new, corrected code
const originalSpeaker = utterance.speaker || 'A';
let displaySpeaker = this.speakerMap[originalSpeaker];

// If a name for this speaker ID doesn't exist yet...
if (!displaySpeaker) {
    // ...create a default one (e.g., "Speaker A")
    displaySpeaker = `Speaker ${originalSpeaker}`;
    // And add it to our map so it can be saved later
    this.speakerMap[originalSpeaker] = displaySpeaker;
}

const utteranceTimestamp = this.formatTime(utterance.start / 1000);
// In the renderTranscript() function in transcribe-ai-viewer.js...
// Find the block that starts with "let textHtml = '';"

// REPLACE that entire block with this new, smarter logic:

let textHtml = '';

// Use the 'is_edited' flag to decide how to render the text.
// This is the key to the fix. It also includes a fallback for older data.
if (utterance.is_edited || !utterance.words || utterance.words.length === 0) {
    // STATE 1: Text has been EDITED.
    // Render from the saved 'text' property and parse for highlight markers.
    const parts = utterance.text.split(/(\[\[HIGHLIGHT color="[^"]+"\]\].*?\[\[\/HIGHLIGHT\]\])/g);
    textHtml = parts.map(part => {
        if (!part) return '';
        const match = /\[\[HIGHLIGHT color="([^"]+)"\]\](.*?)\[\[\/HIGHLIGHT\]\]/.exec(part);
        if (match) {
            const color = match[1];
            const text = match[2];
            return `<mark style="background-color: ${this.escapeHtml(color)};">${this.escapeHtml(text)}</mark>`;
        } else {
            return this.escapeHtml(part);
        }
    }).join('');

} else {
    // STATE 2: Text is ORIGINAL.
    // Render by looping through the 'words' array to create clickable spans.
    let isNewSentence = true;
    utterance.words.forEach(word => {
        if (isNewSentence) {
            const sentenceTimestamp = this.formatTime(word.start / 1000);
            textHtml += `<span class="sentence-timestamp">[${sentenceTimestamp}]</span> `;
            isNewSentence = false;
        }
        textHtml += `<span class="word" data-start="${word.start}" data-end="${word.end}">${this.escapeHtml(word.text)}</span> `;
        if (/[.?!]$/.test(word.text)) {
            isNewSentence = true;
        }
    });
}

// +++ END: THIS IS THE NEW, CORRECTED CODE +++
                html += `
                    <div class="utterance-row" data-index="${index}" data-start="${utterance.start}" 
                         data-end="${utterance.end}" data-original-speaker="${this.escapeHtml(originalSpeaker)}">
                        <div class="utterance-header">
                            <div class="speaker-info">
                                <span class="speaker-avatar" data-speaker="${this.escapeHtml(originalSpeaker)}" 
                                      style="background-color: ${speakerColors[originalSpeaker]}">${this.escapeHtml(displaySpeaker.charAt(0).toUpperCase())}</span>
                                <span class="speaker-label" data-speaker="${this.escapeHtml(originalSpeaker)}" 
                                      title="Click to edit speaker name">${this.escapeHtml(displaySpeaker)}</span>
                            </div>
                            <span class="utterance-timestamp">${this.escapeHtml(utteranceTimestamp)}</span>
                        </div>
                        <p class="utterance-text" contenteditable="false">${textHtml.trim()}</p>
                    </div>`;
            });
            
            this.$transcriptContent.html(html);
            this.applyHighlights();
            this.setTimestampView('utterance');
        }

        buildSearchIndex() {
            this.searchIndex = [];
            
            this.$transcriptContent.find('.utterance-row').each((index, element) => {
                const $element = $(element);
                const text = $element.find('.utterance-text').text()
                    .replace(/\[\d+:\d+\]/g, '') // Remove timestamps
                    .trim();
                
                this.searchIndex.push({
                    index: index,
                    text: text.toLowerCase(),
                    element: element
                });
            });
            
            log(`Search index built with ${this.searchIndex.length} entries`);
        }

        setTimestampView(mode) {
            if (!this.$timestampsBtn || !this.$timestampsBtnLabel) {
                warn('Timestamp controls not found');
                return;
            }

            mode = mode || 'utterance';
            this.currentTimestampMode = mode;

            this.$transcriptContent.removeClass('show-sentence-timestamps hide-utterance-timestamps');
            this.$timestampsMenu.removeClass('open');
            this.$timestampsBtn.attr('aria-expanded', false);

            switch (mode) {
                case 'sentence':
                    this.$transcriptContent.addClass('show-sentence-timestamps hide-utterance-timestamps');
                    this.$timestampsBtnLabel.text('Per Sentence');
                    this.$timestampsBtn.removeClass('active');
                    break;
                case 'none':
                    this.$transcriptContent.addClass('hide-utterance-timestamps');
                    this.$timestampsBtnLabel.text('Hidden');
                    this.$timestampsBtn.removeClass('active');
                    break;
                case 'utterance':
                default:
                    this.$timestampsBtnLabel.text('Per Utterance');
                    break;
            }

            this.updateCopyButtonState();
        }

        // ==========================================
        // COPY SHORTCUTS
        // ==========================================

        handleCopyButtonClick(e) {
            if (!this.$copyBtn || !this.$copyBtn.length) return;

            const clickedArrow = $(e.target).closest('.dropdown-arrow').length > 0;

            if (this.isTranslationVisible() && clickedArrow) {
                e.preventDefault();
                e.stopPropagation();
                this.toggleCopyMenu();
                return;
            }

            this.copyTranscriptText('original');

            if (this.$copyMenu && this.$copyMenu.hasClass('open')) {
                this.toggleCopyMenu(false);
            }
        }

        handleCopyOptionClick(e) {
            e.preventDefault();
            const $option = $(e.currentTarget);

            if ($option.hasClass('disabled')) {
                return;
            }

            const target = $option.data('target') || 'original';
            this.copyTranscriptText(target);
            this.toggleCopyMenu(false);
        }

        toggleCopyMenu(forceState) {
            if (!this.$copyMenu || !this.$copyMenu.length || !this.isTranslationVisible()) {
                return;
            }

            const shouldOpen = typeof forceState === 'boolean'
                ? forceState
                : !this.$copyMenu.hasClass('open');

            this.$copyMenu.toggleClass('open', shouldOpen);
            this.updateCopyButtonState();
        }

        copyTranscriptText(target = 'original') {
            const { text, error } = this.getCopyText(target);

            if (!text) {
                if (error) {
                    this.showNotification(error, 'warning');
                }
                return;
            }

            this.copyToClipboard(text)
                .then(() => {
                    const label = target === 'translation' ? 'Translation' : 'Transcript';
                    this.showNotification(`${label} copied to clipboard.`, 'success');
                })
                .catch(() => {
                    this.showNotification('Unable to copy to clipboard.', 'error');
                });
        }

        getCopyText(target) {
            const options = {
                includeUtteranceTimestamps: false,
                includeSentenceTimestamps: false
            };

            let $container = null;

            if (target === 'translation') {
                if (!this.isTranslationCopyAvailable()) {
                    return { text: '', error: 'Translation not ready yet.' };
                }

                $container = this.$translationContent;
                options.includeUtteranceTimestamps = true;
            } else {
                if (!this.$transcriptContent || !this.$transcriptContent.length ||
                    !this.$transcriptContent.find('.utterance-row').length) {
                    return { text: '', error: 'Transcript not ready yet.' };
                }

                $container = this.$transcriptContent;
                options.includeUtteranceTimestamps = this.currentTimestampMode === 'utterance';
                options.includeSentenceTimestamps = this.currentTimestampMode === 'sentence';
            }

            const text = this.buildCopyTextFromContainer($container, options);

            return {
                text,
                error: text ? null : 'Nothing to copy.'
            };
        }

        buildCopyTextFromContainer($container, options = {}) {
            if (!$container || !$container.length) return '';

            const lines = [];

            $container.find('.utterance-row').each((index, row) => {
                const $row = $(row);
                const speaker = this.normalizeCopiedText($row.find('.speaker-label').text());
                const timestamp = this.normalizeCopiedText($row.find('.utterance-timestamp').text());
                const text = this.extractUtteranceTextForCopy($row.find('.utterance-text'), options.includeSentenceTimestamps);

                if (!text) return;

                const parts = [];

                if (options.includeUtteranceTimestamps && timestamp) {
                    parts.push(`[${timestamp}]`);
                }

                if (speaker) {
                    parts.push(`${speaker}:`);
                }

                parts.push(text);

                const line = this.normalizeCopiedText(parts.join(' '));

                if (line) {
                    lines.push(line);
                }
            });

            return lines.join('\n');
        }

        extractUtteranceTextForCopy($element, includeSentenceTimestamps = false) {
            if (!$element || !$element.length) return '';

            const $clone = $element.clone();

            if (!includeSentenceTimestamps) {
                $clone.find('.sentence-timestamp').remove();
            }

            $clone.find('.utterance-timestamp').remove();

            return this.normalizeCopiedText($clone.text());
        }

        normalizeCopiedText(text) {
            if (!text) return '';
            return text.replace(/\s+/g, ' ').trim();
        }

        copyToClipboard(text) {
            if (!text) return Promise.resolve();

            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }

            return new Promise((resolve, reject) => {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                textarea.setSelectionRange(0, textarea.value.length);

                try {
                    const successful = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (successful) {
                        resolve();
                    } else {
                        reject();
                    }
                } catch (error) {
                    document.body.removeChild(textarea);
                    reject(error);
                }
            });
        }

        isTranslationVisible() {
            return this.$translationWrapper && this.$translationWrapper.is(':visible');
        }

        isTranslationCopyAvailable() {
            return this.isTranslationVisible() &&
                this.$translationContent &&
                this.$translationContent.find('.utterance-row').length > 0 &&
                !this.$translationContent.find('.loading-state').length;
        }

        updateCopyButtonState() {
            if (!this.$copyBtn || !this.$copyBtn.length) return;

            const translationVisible = this.isTranslationVisible();
            const menuIsOpen = this.$copyMenu && this.$copyMenu.hasClass('open');

            this.$copyBtn.toggleClass('has-dropdown', translationVisible);
            this.$copyBtn.toggleClass('menu-open', translationVisible && menuIsOpen);

            if (translationVisible) {
                this.$copyBtn.attr('aria-haspopup', 'true');
                this.$copyBtn.attr('aria-expanded', menuIsOpen ? 'true' : 'false');
                if (this.$copyDropdown && this.$copyDropdown.length) {
                    this.$copyDropdown.addClass('has-translation');
                }
            } else {
                this.$copyBtn.removeAttr('aria-haspopup');
                this.$copyBtn.removeAttr('aria-expanded');
                if (this.$copyMenu && this.$copyMenu.length) {
                    this.$copyMenu.removeClass('open');
                }
                if (this.$copyDropdown && this.$copyDropdown.length) {
                    this.$copyDropdown.removeClass('has-translation');
                }
                menuIsOpen && this.$copyBtn.removeClass('menu-open');
            }

            if (this.$copyTranslationOption && this.$copyTranslationOption.length) {
                const translationReady = this.isTranslationCopyAvailable();
                this.$copyTranslationOption.toggleClass('disabled', !translationReady);

                if (translationVisible) {
                    this.$copyTranslationOption.attr('aria-disabled', translationReady ? 'false' : 'true');
                } else {
                    this.$copyTranslationOption.removeAttr('aria-disabled');
                }
            }
        }

        // ==========================================
        // TITLE & SPEAKER EDITING - IMPROVED
        // ==========================================

        enableTitleEdit() {
            if (!this.transcriptData.can_edit) return;
            this.$title.attr('contenteditable', 'true').addClass('editing-title').focus();
            document.execCommand('selectAll', false, null);
        }

        handleTitleEdit(e) {
            if (e.type === 'blur' || e.key === 'Enter') {
                e.preventDefault();
                const newTitle = this.$title.text().trim();
                const oldTitle = this.transcriptData.title;
                this.$title.attr('contenteditable', 'false').removeClass('editing-title');
                
                if (newTitle && newTitle !== oldTitle) {
                    this.saveTitle(newTitle);
                } else {
                    this.$title.text(oldTitle);
                }
            } else if (e.key === 'Escape') {
                this.$title.text(this.transcriptData.title)
                    .attr('contenteditable', 'false')
                    .removeClass('editing-title')
                    .blur();
            }
        }

        saveTitle(newTitle) {
            this.showLoading(true);
            
            $.post(this.ajaxUrl, {
                action: 'update_transcript_title',
                transcript_id: this.transcriptId,
                title: newTitle,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    this.transcriptData.title = newTitle;
                    this.showNotification('Title updated!', 'success');
                } else {
                    this.$title.text(this.transcriptData.title);
                    this.showNotification(res.data || 'Failed to update title', 'error');
                }
            })
            .fail(() => {
                this.$title.text(this.transcriptData.title);
                this.showNotification('Network error saving title', 'error');
            })
            .always(() => this.showLoading(false));
        }

        enableSpeakerEdit(e) {
            if (!this.transcriptData.can_edit) return;
            
            const $label = $(e.currentTarget);
            const originalSpeaker = $label.data('speaker');
            const displayName = this.speakerMap[originalSpeaker] || originalSpeaker;
            
            $label.attr('contenteditable', 'true')
                .addClass('editing-speaker')
                .text(displayName)
                .focus();
            document.execCommand('selectAll', false, null);
        }

        handleSpeakerEdit(e) {
            const $label = $(e.currentTarget);
            const originalSpeaker = $label.data('speaker');
            const currentDisplayName = this.speakerMap[originalSpeaker] || originalSpeaker;

            if (e.type === 'blur' || e.key === 'Enter') {
                e.preventDefault();
                const newSpeakerName = $label.text().trim();
                $label.attr('contenteditable', 'false').removeClass('editing-speaker');
                
                if (newSpeakerName && newSpeakerName !== currentDisplayName) {
                    // Check for name collisions
                    const nameExists = Object.values(this.speakerMap).includes(newSpeakerName) &&
                                      this.speakerMap[originalSpeaker] !== newSpeakerName;
                    if (nameExists) {
                        this.showNotification(`Speaker name "${newSpeakerName}" is already in use. Please choose a different name.`, 'warning');
                        $label.text(currentDisplayName);
                        return;
                    }
                    
                    this.updateSpeakerName(originalSpeaker, newSpeakerName);
                        this.saveTranscript(); // <-- AutoChangeTheName
                } else {
                    $label.text(currentDisplayName);
                }
            } else if (e.key === 'Escape') {
                $label.text(currentDisplayName)
                    .attr('contenteditable', 'false')
                    .removeClass('editing-speaker')
                    .blur();
            }
        }

        updateSpeakerName(originalSpeaker, newName) {
            this.speakerMap[originalSpeaker] = newName;
            this.hasUnsavedChanges = true;
            
            // Update all instances without "Speaker" prefix
            $(`.speaker-label[data-speaker="${originalSpeaker}"]`).each(function() {
                $(this).text(newName);
            });
            
            $(`.speaker-avatar[data-speaker="${originalSpeaker}"]`).each(function() {
                $(this).text(newName.charAt(0).toUpperCase());
            });
            
		this.showNotification(`Speaker name updated to "${newName}" and saved.`, 'success');
        }

        // ==========================================
        // EDITING & SAVING
        // ==========================================

        toggleEdit() {
            this.isEditing = !this.isEditing;
            this.$transcriptContent.toggleClass('editing', this.isEditing);
            this.$editBtn.toggleClass('active', this.isEditing).html(
                this.isEditing ? 
                '<span class="material-symbols-outlined">save</span> Save' : 
                '<span class="material-symbols-outlined">edit</span> Edit'
            );
            
            if (this.isEditing) {
                $('.utterance-text').attr('contenteditable', 'true');
                this.showNotification('Editing enabled. Changes auto-save every 2 minutes. Click Save when finished.', 'info');
            } else {
                $('.utterance-text').attr('contenteditable', 'false');
                this.saveTranscript();
            }
        }
        


// In transcribe-ai-viewer.js, replace the entire function
saveTranscript(showNotification = true) {
    if (this.isSaving) {
        this.saveQueue.push(showNotification);
        return;
    }

    const self = this;
    let hasChanges = false;
    const dataToSave = JSON.parse(JSON.stringify(this.transcriptData.data));

    // Helper function to extract text excluding timestamps
    const getTextExcludingTimestamps = (element) => {
        let text = '';
        $(element).contents().each(function() {
            // Skip sentence-timestamp and utterance-timestamp spans entirely
            if (this.nodeType === 1 && 
                ($(this).hasClass('sentence-timestamp') || 
                 $(this).hasClass('utterance-timestamp'))) {
                return; // Skip timestamps
            }
            
            if (this.nodeType === 3) {
                // Text node - add its content
                text += this.textContent;
            } else if (this.nodeType === 1) {
                // Element node - recurse into it
                text += getTextExcludingTimestamps(this);
            }
        });
        return text;
    };

    $('.utterance-row').each(function(index) {
        const $textElement = $(this).find('.utterance-text');
        const newUtterance = dataToSave.utterances[index];
        const originalSavedText = self.transcriptData.data.utterances[index].text;

        const hasWordSpans = $textElement.find('.word').length > 0;
        let reconstructedText = '';

        if (hasWordSpans) {
            // STATE 1: UN-EDITED TEXT - use recursive function for ALL elements
            $textElement.contents().each(function() {
                const node = this;
                
                // Skip timestamp spans
                if (node.nodeType === 1 && 
                    ($(node).hasClass('sentence-timestamp') || 
                     $(node).hasClass('utterance-timestamp'))) {
                    return; // Skip
                }
                
                if (node.nodeType === 1 && $(node).is('mark.word-based-highlight')) {
                    // This is a highlight mark - extract text excluding timestamps
                    const $mark = $(node);
                    const hexColor = self.rgbToHex($mark.css('background-color'));
                    const textInside = getTextExcludingTimestamps(node);
                    reconstructedText += `[[HIGHLIGHT color="${hexColor}"]]${textInside}[[/HIGHLIGHT]]`;
                } else if (node.nodeType === 1 && $(node).is('.word')) {
                    // Regular word span
                    reconstructedText += getTextExcludingTimestamps(node);
                } else if (node.nodeType === 3) {
                    // Text node
                    reconstructedText += node.textContent;
                } else if (node.nodeType === 1) {
                    // Other element - recurse
                    reconstructedText += getTextExcludingTimestamps(node);
                }
            });
       } else {
            // STATE 2: EDITED TEXT
            // --- FIX START ---
            // This logic now mirrors the `if (hasWordSpans)` block,
            // but looks for any <mark> tag, not just `.word-based-highlight`.
            $textElement.contents().each(function() {
                const node = this;
                
                if (node.nodeType === 1 && 
                    ($(node).hasClass('sentence-timestamp') || 
                     $(node).hasClass('utterance-timestamp'))) {
                    return; // Skip timestamps
                }
                
                if (node.nodeType === 1 && $(node).is('mark')) { // Check for ANY mark tag
                    const $mark = $(node);
                    // Use the rgbToHex helper function to get the color
                    const hexColor = self.rgbToHex($mark.css('background-color')); 
                    const textInside = getTextExcludingTimestamps(node);
                    reconstructedText += `[[HIGHLIGHT color="${hexColor}"]]${textInside}[[/HIGHLIGHT]]`;
                } else if (node.nodeType === 3) {
                    // Text node
                    reconstructedText += node.textContent;
                } else if (node.nodeType === 1) {
                    // Other element (like a .word span inside an edited block)
                    reconstructedText += getTextExcludingTimestamps(node);
                }
            });
            // --- FIX END ---
        }
        // Clean up the reconstructed text
        reconstructedText = reconstructedText.replace(/\s+/g, ' ').trim();

        if (reconstructedText !== originalSavedText) {
            hasChanges = true;
            newUtterance.is_edited = true;
            newUtterance.text = reconstructedText;
        }
    });

    // Check if speaker map changed
    if (JSON.stringify(this.speakerMap) !== JSON.stringify(this.transcriptData.speaker_map || {})) {
        hasChanges = true;
    }

    if (!hasChanges && !this.hasUnsavedChanges) {
        if (showNotification) this.showNotification('No changes to save.', 'info');
        return;
    }

    this.isSaving = true;
    if (showNotification) this.showLoading(true);

    $.post(this.ajaxUrl, {
        action: 'save_transcript',
        transcript_id: this.transcriptId,
        transcript_data: JSON.stringify(dataToSave),
        speaker_map: JSON.stringify(this.speakerMap),
        nonce: this.nonce
    })
   .done(res => {
                if (res.success) {
                    // Always update the data in the background
                    this.hasUnsavedChanges = false;
                    this.transcriptData.data = dataToSave;
                    this.transcriptData.speaker_map = this.speakerMap;
                    
                    if (showNotification) {
                        // This was a MANUAL save.
                        // Show notification and re-render the transcript.
                        this.showNotification('Transcript saved!', 'success');
                        this.renderTranscript();
                        this.applyHighlights();
                    } else {
                        // This was an AUTOSAVE.
                        // Do NOT re-render, just log it.
                        console.log('Autosave successful.');
                    }
                } else {
                    if (showNotification) this.showNotification(res.data || 'Save failed', 'error');
                }
            })
    .fail(() => {
        if (showNotification) this.showNotification('Network error.', 'error');
    })
    .always(() => {
        this.isSaving = false;
        if (showNotification) this.showLoading(false);
        if (this.saveQueue.length > 0) {
            this.saveTranscript(this.saveQueue.shift());
        }
    });
}

//FUNC ENDS

mergeAdjacentHighlights(segments) {
    if (segments.length <= 1) return segments;
    
    // Sort by start position
    segments.sort((a, b) => a.start - b.start);
    
    const merged = [];
    let current = segments[0];
    
    for (let i = 1; i < segments.length; i++) {
        const next = segments[i];
        
        // Check if segments are adjacent or overlapping and have same color
        if (current.end >= next.start - 1 && current.color === next.color) {
            // Merge segments
            current = {
                start: current.start,
                end: Math.max(current.end, next.end),
                color: current.color,
                text: (current.text + ' ' + next.text).trim()
            };
        } else {
            merged.push(current);
            current = next;
        }
    }
    
    merged.push(current);
    return merged;
}

updateHighlightsForEditedText() {
    // Update highlights to use text-based matching for edited utterances
    this.highlights.forEach(highlight => {
        // Find the utterance containing this highlight
        const utterances = this.transcriptData.data.utterances;
        
        for (let i = 0; i < utterances.length; i++) {
            const utterance = utterances[i];
            
            // If utterance is edited and contains the highlight text
            if (utterance.is_edited && utterance.text.includes(highlight.highlight_text)) {
                // Mark this highlight as text-based rather than timestamp-based
                highlight.text_based = true;
                highlight.utterance_index = i;
            }
        }
    });
}
        destroy() {
            this.eventHandlers.forEach(handler => {
                if (handler.selector) {
                    handler.element.off(handler.event, handler.selector, handler.handler);
                } else {
                    handler.element.off(handler.event, handler.handler);
                }
            });
            
            if (this.autoSaveInterval) {
                clearInterval(this.autoSaveInterval);
            }
            
            if (this.searchDebounce) {
                clearTimeout(this.searchDebounce);
            }
            
            if (this.audioPlayer) {
                this.audioPlayer.pause();
                this.audioPlayer.src = '';
            }
        }

        // ==========================================
        // IMPROVED SEARCH FUNCTIONALITY
        // ==========================================

      toggleSearch() {
            // --- ADDED FIX ---
            if (this.hasUnsavedChanges && this.isEditing) {
                this.showNotification('Syncing changes before search...', 'info');
                // Trigger a save, but don't show the "Saved!" notification
                this.saveTranscript(false); 
                // Don't open the search box yet. The user will click again after saving.
                return; 
            }
            // --- END FIX ---

            const isOpen = this.$searchBox.hasClass('open');
            this.$searchBox.toggleClass('open', !isOpen);
            this.$searchBtn.attr('aria-expanded', !isOpen);

            if (!isOpen) {
                this.$searchInput.focus();
            } else {
                this.clearSearch();
            }
        }
  performSearch(query) {
            this.clearSearch(); // This already removes all <mark> tags
            
            if (!query || query.trim().length < 2) {
                this.$searchCounter.text('');
                return;
            }

            const matchCase = this.$searchOptionsMenu.find('[data-option="matchCase"]').hasClass('active');
            
            // 1. Highlight all matching terms
            this.$transcriptContent.find('.utterance-row').each((index, element) => {
                const $element = $(element);
                const $text = $element.find('.utterance-text');
                
                // Get clean text to check if a match exists
                let textContent = '';
                $text.contents().each(function() {
                    if (this.nodeType === Node.TEXT_NODE) {
                        textContent += this.textContent;
                    } else if ($(this).hasClass('word') && !$(this).hasClass('sentence-timestamp')) {
                        textContent += $(this).text() + ' ';
                    } else if (!$(this).hasClass('sentence-timestamp') && !$(this).hasClass('utterance-timestamp')) {
                        textContent += $(this).text() + ' ';
                    }
                });
                textContent = textContent.trim();
                const compareText = matchCase ? textContent : textContent.toLowerCase();
                const searchQuery = matchCase ? query : query.toLowerCase();

                if (compareText.includes(searchQuery)) {
                    // This function adds the <mark> tags
                    this.highlightSearchTermInElement($text, query, matchCase);
                }
            });
            
            // 2. Collect all created <mark> elements
            this.searchResults = this.$transcriptContent.find('mark.search-highlight').get();
            
            // 3. Update UI
            if (this.searchResults.length > 0) {
                this.currentSearchIndex = 0;
                this.jumpToSearchResult(0); // This will highlight the first one
            } else {
                this.$searchCounter.text('No results');
            }

            // Update counter (do this last, after jumpToSearchResult)
            this.updateSearchCounter();
        }
        
        highlightSearchTermInElement($element, searchText, matchCase) {
            const regex = new RegExp(`(${searchText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, matchCase ? 'g' : 'gi');
            
            // Process each text node and word span
            const processNode = (node) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    const text = node.textContent;
                    if (regex.test(text)) {
                        const span = document.createElement('span');
                        span.innerHTML = text.replace(regex, '<mark class="search-highlight">$1</mark>');
                        node.parentNode.replaceChild(span, node);
                    }
                } else if (node.classList && node.classList.contains('word')) {
                    const text = node.textContent;
                    if (regex.test(text)) {
                        node.innerHTML = text.replace(regex, '<mark class="search-highlight">$1</mark>');
                    }
                } else if (!node.classList || (!node.classList.contains('sentence-timestamp') && !node.classList.contains('utterance-timestamp'))) {
                    // Recursively process child nodes
                    Array.from(node.childNodes).forEach(child => processNode(child));
                }
            };
            
            $element.contents().each(function() {
                processNode(this);
            });
        }

       clearSearch() {
            // Remove all search highlights by unwrapping them
            this.$transcriptContent.find('mark.search-highlight').each(function() {
                const parent = this.parentNode;
                while (this.firstChild) {
                    parent.insertBefore(this.firstChild, this);
                }
                parent.removeChild(this);
            });
            
            // Normalize text nodes to merge adjacent text fragments
            this.$transcriptContent.find('.utterance-text').each(function() {
                this.normalize();
            });
            
            this.searchResults = [];
            this.currentSearchIndex = 0;
            this.$searchCounter.text('');
        }
        navigateSearch(direction) {
            if (this.searchResults.length === 0) return;
            
            if (direction === 'next') {
                this.currentSearchIndex = (this.currentSearchIndex + 1) % this.searchResults.length;
            } else {
                this.currentSearchIndex = (this.currentSearchIndex - 1 + this.searchResults.length) % this.searchResults.length;
            }
            
            this.jumpToSearchResult(this.currentSearchIndex);
        }

   jumpToSearchResult(index) {
            if (!this.searchResults || this.searchResults.length === 0) return;

            // Remove .current class from *all* search highlights
            this.$transcriptContent.find('mark.search-highlight').removeClass('current');
            
            // Add .current class to the new target <mark>
            const $currentMark = $(this.searchResults[index]);
            $currentMark.addClass('current');
            
            // Scroll to the parent utterance row
            const $utterance = $currentMark.closest('.utterance-row');
            if ($utterance.length) {
                let stickyOffset = 0;
                const $sticky = $('.sticky-container');
                if ($sticky.hasClass('is-sticky')) {
                    stickyOffset = $sticky.outerHeight();
                }
                
                // Calculate scroll position relative to the viewport
                const elementTop = $utterance.offset().top;
                const viewportTop = $(window).scrollTop();
                const viewportHeight = $(window).height();

                // Only scroll if the element is not already in view
                if (elementTop < viewportTop + stickyOffset || elementTop > viewportTop + viewportHeight - 100) {
                     $('html, body').animate({ 
                         scrollTop: elementTop - stickyOffset - 100 
                     }, 300);
                }
            }

            this.updateSearchCounter();
        }
     updateSearchCounter() {
            if (this.searchResults.length > 0) {
                this.$searchCounter.text(`${this.currentSearchIndex + 1} / ${this.searchResults.length}`);
            } else {
                this.$searchCounter.text('');
            }
        }
        
        performReplaceAll() {
            const findText = this.$searchInput.val();
            const replaceText = this.$replaceInput.val();

            if (!findText) {
                this.showNotification('Please enter text to find.', 'warning');
                return;
            }

            if (!confirm(`Replace all occurrences of "${findText}" with "${replaceText}"?`)) {
                return;
            }
            
            if (this.undoStack.length >= this.MAX_UNDO_STACK) {
                this.undoStack.shift();
            }
            this.undoStack.push(JSON.parse(JSON.stringify(this.transcriptData.data)));
            
            let replacementCount = 0;
            const matchCase = this.$searchOptionsMenu.find('[data-option="matchCase"]').hasClass('active');

            this.transcriptData.data.utterances.forEach(utterance => {
                const regex = new RegExp(
                    findText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
                    matchCase ? 'g' : 'gi'
                );
                
                if (utterance.text && utterance.text.match(regex)) {
                    const matches = utterance.text.match(regex);
                    replacementCount += matches.length;
                    utterance.text = utterance.text.replace(regex, replaceText);
                    utterance.is_edited = true;
                }
            });

            if (replacementCount > 0) {
                this.clearSearch();
                this.$searchInput.val('');
                this.renderTranscript();
                this.buildSearchIndex();
                this.showNotification(`Replaced ${replacementCount} occurrence(s).`, 'success');
                
                this.hasUnsavedChanges = true;
                if (!this.isEditing) {
                    this.toggleEdit();
                }
            } else {
                this.showNotification(`"${findText}" not found.`, 'info');
            }
        }

        // ==========================================
        // AUDIO PLAYER (unchanged)
        // ==========================================

        initializeAudio() {
            if (this.config.audio_url && this.audioPlayer) {
                this.audioPlayer.src = this.config.audio_url;
            }
        }

        onAudioLoaded() {
            this.$totalTime.text(this.formatTime(this.audioPlayer.duration));
            this.$progressBar.attr('max', this.audioPlayer.duration);
        }

        onPlay() {
            this.$playBtn.find('.material-symbols-outlined').text('pause');
        }

        onPause() {
            this.$playBtn.find('.material-symbols-outlined').text('play_arrow');
        }

        onEnded() {
            this.onPause();
        }

        onTimeUpdate() {
            if (!this.audioPlayer || isNaN(this.audioPlayer.duration)) return;
            
            const currentTime = this.audioPlayer.currentTime;
            this.$currentTime.text(this.formatTime(currentTime));
            this.$progressBar.val(currentTime);
            this.$progressBar.css('--progress', `${(currentTime / this.audioPlayer.duration) * 100}%`);
            this.highlightCurrentElements(currentTime * 1000);
        }

// In transcribe-ai-viewer.js, REPLACE the entire highlightCurrentElements function with this:
highlightCurrentElements(timeMs) {
    // **FIX: Don't update highlights when user is selecting text**
    if (this.isSelectingText) {
        return;
    }
    
    // First, clean up any highlight from the previous check
    if (this.dynamicHighlightSpan) {
        $(this.dynamicHighlightSpan).contents().unwrap();
        this.dynamicHighlightSpan = null;
    }
    $('.word.active-word').removeClass('active-word');

    let activeUtteranceFound = false;
    const self = this;

    $('.utterance-row').each(function() {
        const $row = $(this);
        
        // Deactivate rows that are not the current one
        if (!activeUtteranceFound && timeMs >= $row.data('start') && timeMs <= $row.data('end')) {
            $row.addClass('active');
            activeUtteranceFound = true;
            
            const utteranceIndex = $row.data('index');
            const utteranceData = self.transcriptData.data.utterances[utteranceIndex];

            // CASE 1: Original text with existing .word spans
            if ($row.find('.word').length > 0) {
                let activeWordFound = false;
                $row.find('.word').each(function() {
                    const $word = $(this);
                    if (!activeWordFound && timeMs >= $word.data('start') && timeMs <= $word.data('end')) {
                        // Always add active-word to the word span itself, regardless of whether it's in a highlight
                        $word.addClass('active-word');
                        activeWordFound = true;
                    }
                });
                return; // Done with this utterance
            }

            // CASE 2: Edited text (no .word spans, but we have preserved timing data)
            if (utteranceData.words && utteranceData.words.length > 0) {
                let activeWordData = null;
                let wordIndexInOriginalData = -1; // The index (0, 1, 2...) of the active word

                for (let i = 0; i < utteranceData.words.length; i++) {
                    const word = utteranceData.words[i];
                    if (timeMs >= word.start && timeMs <= word.end) {
                        activeWordData = word;
                        wordIndexInOriginalData = i;
                        break;
                    }
                }

                if (activeWordData) {
                    // Check if this word time overlaps with any highlight
                    const isInHighlight = self.highlights.some(h => 
                        h.start_time !== null && 
                        h.end_time !== null &&
                        activeWordData.start >= h.start_time && 
                        activeWordData.end <= h.end_time
                    );

                    if (isInHighlight) {
                        // Word is in a highlight - don't create dynamic span
                        return;
                    }

                    // --- START: FIX FOR EDITED WORD HIGHLIGHTING ---
                    // Find the character offsets for the Nth word *in the current DOM text*.
                    
                    const $textElement = $row.find('.utterance-text');
                    const domText = $textElement.text();
                    
                    // This regex splits text into words and the spaces between them
                    const domTextParts = domText.split(/(\s+)/).filter(part => part.length > 0);

                    let startCharOffset = 0;
                    let endCharOffset = -1;
                    let currentWordIndexInDom = -1;
                    
                    for (const part of domTextParts) {
                        const isWord = /\S/.test(part); // Is it a word or just whitespace?
                        if (isWord) {
                            currentWordIndexInDom++;
                        }

                        if (currentWordIndexInDom === wordIndexInOriginalData) {
                            // This is the word we want to highlight
                            endCharOffset = startCharOffset + part.length;
                            break; // We found our word and its offsets
                        }
                        
                        startCharOffset += part.length;
                    }

                    if (endCharOffset !== -1) {
                        // We found the Nth word in the DOM. Highlight it.
                        const wrapRange = (element, start, end) => {
                            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, null, false);
                            let charCount = 0;
                            let startNode, endNode, startOffset, endOffset;

                            while (walker.nextNode()) {
                                const node = walker.currentNode;
                                // Ignore text nodes inside our own dynamic highlights or search highlights
                                if ($(node.parentElement).hasClass('active-word') || $(node.parentElement).hasClass('search-highlight')) {
                                    continue;
                                }
                                
                                const nodeLength = node.nodeValue.length;

                                if (startNode === undefined && start < charCount + nodeLength) {
                                    startNode = node;
                                    startOffset = start - charCount;
                                }
                                if (endNode === undefined && end <= charCount + nodeLength) {
                                    endNode = node;
                                    endOffset = end - charCount;
                                    break;
                                }
                                charCount += nodeLength;
                            }

                            if (startNode && endNode) {
                                const range = document.createRange();
                                range.setStart(startNode, startOffset);
                                range.setEnd(endNode, endOffset);
                                
                                const span = document.createElement('span');
                                span.className = 'word active-word';
                                try {
                                    range.surroundContents(span);
                                    self.dynamicHighlightSpan = span;
                                } catch (e) {
                                    // Range invalid (e.g., spans across highlight boundaries), ignore
                                }
                            }
                        };
                        
                        wrapRange($textElement[0], startCharOffset, endCharOffset);
                    }
                    // --- END: FIX ---
                }
            }

        } else {
            $row.removeClass('active');
        }
    });
}
        togglePlayPause() {
            if (!this.audioPlayer) return;
            
            if (this.audioPlayer.paused) {
                this.audioPlayer.play().catch(e => {
                    console.error('Play failed:', e);
                    this.showNotification('Unable to play audio.', 'error');
                });
            } else {
                this.audioPlayer.pause();
            }
        }

        skip(seconds) {
            if (!this.audioPlayer) return;
            this.audioPlayer.currentTime = Math.max(0, 
                Math.min(this.audioPlayer.duration, this.audioPlayer.currentTime + seconds));
        }

        seekTo(time) {
            if (!this.audioPlayer) return;
            const seekTime = parseFloat(time);
            if (!isNaN(seekTime) && this.audioPlayer.duration) {
                this.audioPlayer.currentTime = Math.max(0, Math.min(this.audioPlayer.duration, seekTime));
            }
        }

        cycleSpeed() {
            if (!this.audioPlayer) return;
            const speeds = [1, 1.25, 1.5, 2, 0.75];
            const currentSpeed = this.audioPlayer.playbackRate;
            const nextIndex = (speeds.indexOf(currentSpeed) + 1) % speeds.length;
            this.audioPlayer.playbackRate = speeds[nextIndex];
            this.$speedBtn.text(speeds[nextIndex] + 'x');
        }

        handleKeyboard(e) {
            if ($(e.target).is('input, [contenteditable="true"]')) return;
            
            const keyMap = {
                ' ': () => this.togglePlayPause(),
                'ArrowLeft': () => this.skip(-5),
                'ArrowRight': () => this.skip(5),
                'Escape': () => {
                    $('.modal').fadeOut(200);
                    $('.control-dropdown-menu.open').removeClass('open');
                }
            };
            
            if (keyMap[e.key]) {
                e.preventDefault();
                keyMap[e.key]();
            }
        }

        // Continue with the rest of the methods...
        // (Summary, Highlights, Translation, Export sections remain mostly the same)

        toggleSummary() {
            this.$summaryBox.slideToggle(200);
            this.$summaryBtn.toggleClass('active');
            
            if (this.$summaryBox.is(':visible') && !this.$summaryBox.data('loaded')) {
                this.loadSummary();
            }
        }

        loadSummary() {
            const $summaryContent = $('#summaryContent');
            $summaryContent.html('<div class="loading-state"><div class="spinner"></div><p>Loading summary...</p></div>');
            
            $.post(this.ajaxUrl, {
                action: 'generate_summary',
                transcript_id: this.transcriptId,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    this.displaySummary(res.data.summary, res.data.chapters);
                    this.$summaryBox.data('loaded', true);
                } else {
                    $summaryContent.html('<p class="error-message">Failed to load summary.</p>');
                }
            })
            .fail(() => {
                $summaryContent.html('<p class="error-message">Network error.</p>');
            });
        }

        displaySummary(summary, chapters) {
            const $summaryContent = $('#summaryContent');
            let html = '';
            
            if (summary) {
                html += `<div class="summary-section">
                    <h4>Summary</h4>
                    <div class="summary-text">${this.escapeHtml(summary)}</div>
                </div>`;
            }
            
            if (chapters && chapters.length > 0) {
                html += '<div class="chapters-section"><h4>Chapters</h4><div class="chapters-list">';
                chapters.forEach(chapter => {
                    const time = this.formatTime(chapter.start / 1000);
                    html += `<div class="chapter-item" data-start="${chapter.start}">
                        <span class="chapter-time">${time}</span>
                        <span class="chapter-title">${this.escapeHtml(chapter.headline || chapter.summary)}</span>
                    </div>`;
                });
                html += '</div></div>';
            }
            
            if (!html) {
                html = '<p>No summary available.</p>';
            }
            
            $summaryContent.html(html);
            
            $summaryContent.find('.chapter-item').on('click', (e) => {
                const startTime = $(e.currentTarget).data('start') / 1000;
                this.seekTo(startTime);
            });
        }

        // Highlights section
         toggleHighlightMode() {
            this.highlightMode = !this.highlightMode;
            this.$highlightBtn.toggleClass('active', this.highlightMode);
            this.$transcriptContent.toggleClass('highlight-mode', this.highlightMode);
            
            if (this.highlightMode) {
                this.showNotification('Select text to highlight. Click on highlights to view notes.', 'info');
                this.$highlightBox.slideDown(200);
                this.loadHighlights();
            } else {
                this.$highlightBox.slideUp(200);
            }
        }
// In transcribe-ai-viewer.js, replace the entire function

handleTextSelection(e) {
    if (!this.highlightMode) return;

    const selection = window.getSelection();
    if (!selection.rangeCount || selection.isCollapsed) return;

    const selectedText = selection.toString().trim();
    if (selectedText.length === 0) return;

    const range = selection.getRangeAt(0);
    const $ancestor = $(range.commonAncestorContainer);
    const $utteranceRow = $ancestor.closest('.utterance-row');
    if (!$utteranceRow.length) return;

    // Check if the text is original (has word spans) or edited (no word spans)
    const hasWordSpans = $utteranceRow.find('.word').length > 0;
    
    let startTime = null;
    let endTime = null;

    if (hasWordSpans) {
        // This is original text, so we can get timecodes from the word spans
        const utteranceWords = $utteranceRow.find('.word').toArray();
        let startWord, endWord;
        for (const node of utteranceWords) {
            if (selection.containsNode(node, true)) {
                if (!startWord) startWord = node;
                endWord = node;
            }
        }
        if (startWord && endWord) {
            startTime = $(startWord).data('start');
            endTime = $(endWord).data('end');
        }
    }
    
    // The callback function that will be executed when "Save Highlight" is clicked
    const executeHighlight = (color) => {
        if (!hasWordSpans) {
            // This is EDITED text. We directly wrap the selection in a <mark> tag.
            const mark = document.createElement('mark');
            mark.style.backgroundColor = color;
            range.surroundContents(mark);
            selection.removeAllRanges();
            this.hasUnsavedChanges = true; // Flag the change for saving
        }
    };
    
    this.showHighlightPopup(selectedText, startTime, endTime, e.clientX, e.clientY + 20, executeHighlight);
}


// In transcribe-ai-viewer.js, replace the entire function        

		showHighlightPopup(text, startTime, endTime, x, y, executeHighlightCallback) {
            $('.highlight-popup').remove();
            
            const popup = $(`
                <div class="highlight-popup" style="position: fixed; left: ${x}px; top: ${y}px; z-index: 10000;">
                    <div class="highlight-colors">
                        <span class="color-option selected" data-color="#ffeb3b" style="background: #ffeb3b"></span>
                        <span class="color-option" data-color="#a7ffeb" style="background: #a7ffeb"></span>
                        <span class="color-option" data-color="#ff9f9c" style="background: #ff9f9c"></span>
                        <span class="color-option" data-color="#b4a7d6" style="background: #b4a7d6"></span>
                    </div>
                    <input type="text" class="highlight-note" placeholder="Add note (optional)">
                    <button class="save-highlight-btn">Save Highlight</button>
                </div>
            `);
            
            $('body').append(popup);
            
            // Position the popup
            const popupWidth = popup.outerWidth();
            const popupHeight = popup.outerHeight();
            const windowWidth = $(window).width();
            const windowHeight = $(window).height();
            if (x + popupWidth > windowWidth) { 
                popup.css('left', windowWidth - popupWidth - 20); 
            }
            if (y + popupHeight > windowHeight) { 
                popup.css('top', y - popupHeight - 40); 
            }
            
            let selectedColor = '#ffeb3b';
            popup.find('.color-option').on('click', function() {
                selectedColor = $(this).data('color');
                popup.find('.color-option').removeClass('selected');
                $(this).addClass('selected');
            });

            popup.find('.save-highlight-btn').on('click', () => {
                const note = popup.find('.highlight-note').val();
                
                // Save the highlight and get the ID
                this.saveHighlight(text, startTime, endTime, selectedColor, note, (highlightId) => {
                    // If it's edited text, apply the visual highlight immediately with the ID
                    if (startTime === null && executeHighlightCallback) {
                        executeHighlightCallback(selectedColor, highlightId);
                    }
                });

                popup.remove();
                window.getSelection().removeAllRanges();
            });
        }

        saveHighlight(text, startTime, endTime, color, note, callback) {
            $.post(this.ajaxUrl, {
                action: 'save_highlight',
                transcript_id: this.transcriptId,
                text: text,
                start_time: startTime,
                end_time: endTime,
                color: color,
                note: note,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    this.highlights.push({
                        id: res.data.id,
                        highlight_text: text,
                        start_time: startTime,
                        end_time: endTime,
                        color: color,
                        note: note
                    });
                    
                    // For text-based highlights, we don't need to re-render
                    if (startTime !== null) {
                        this.applyHighlights();
                    }
                    
                    this.updateHighlightsList();
                    this.showNotification('Highlight saved!', 'success');
                    
                    // Call the callback with the highlight ID
                    if (callback) {
                        callback(res.data.id);
                    }
                } else {
                    this.showNotification('Failed to save highlight', 'error');
                }
            })
            .fail(() => this.showNotification('Network error', 'error'));
        }

        loadHighlights() {
            $.post(this.ajaxUrl, {
                action: 'get_highlights',
                transcript_id: this.transcriptId,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    this.highlights = res.data;
                    this.applyHighlights();
                    this.updateHighlightsList();
                }
            });
        }
// In transcribe-ai-viewer.js, REPLACE the entire applyHighlights() function with this:
// In transcribe-ai-viewer.js, REPLACE the entire applyHighlights() function with this:// In transcribe-ai-viewer.js, REPLACE the entire applyHighlights() function with this final version:

        applyHighlights() {
            // Clear any old highlights to prevent duplication and ensure a clean slate.
            this.$transcriptContent.find('mark.word-based-highlight').contents().unwrap();

            const timeBasedHighlights = this.highlights.filter(h => h.start_time !== null);
            if (timeBasedHighlights.length === 0) return;

            // Process each utterance that has not been edited (i.e., still contains .word spans).
            this.$transcriptContent.find('.utterance-row:has(.word)').each((index, row) => {
                const $words = $(row).find('.word');
                if ($words.length === 0) return;

                let i = 0;
                while (i < $words.length) {
                    const firstWordNode = $words.get(i);
                    const $firstWord = $(firstWordNode);
                    let currentHighlight = null;

                    // Check if the current word belongs to any highlight.
                    for (const h of timeBasedHighlights) {
                        if ($firstWord.data('start') >= h.start_time && $firstWord.data('start') <= h.end_time) {
                            currentHighlight = h;
                            break;
                        }
                    }

                    if (currentHighlight) {
                        // A highlight sequence has started. Find where it ends.
                        let lastWordNode = firstWordNode;
                        let j = i + 1;
                        while (j < $words.length) {
                            const nextWordNode = $words.get(j);
                            let nextWordHighlight = null;
                            for (const h of timeBasedHighlights) {
                                if ($(nextWordNode).data('start') >= h.start_time && $(nextWordNode).data('start') <= h.end_time) {
                                    nextWordHighlight = h;
                                    break;
                                }
                            }
                            if (nextWordHighlight && nextWordHighlight.id === currentHighlight.id) {
                                lastWordNode = nextWordNode;
                                j++;
                            } else {
                                break; // The sequence ends here.
                            }
                        }

                        // --- THIS IS THE KEY TO THE FIX ---
                        const range = document.createRange();

                        // Check the node immediately before our first word.
                        const precedingNode = firstWordNode.previousSibling;
                        if (precedingNode && precedingNode.nodeType === 1 && precedingNode.classList.contains('sentence-timestamp')) {
                            // If it's a timestamp, start the highlight AFTER it.
                            range.setStartAfter(precedingNode);
                        } else {
                            // Otherwise, start before the word to include the space.
                            range.setStartBefore(firstWordNode);
                        }
                        
                        // Set the end of the range, which is always correct.
                        range.setEndAfter(lastWordNode);

                        // Create the <mark> tag and wrap the correctly defined range.
                        const mark = document.createElement('mark');
                        mark.className = 'word-based-highlight';
                        mark.style.backgroundColor = currentHighlight.color;
                        mark.dataset.highlightId = currentHighlight.id;
                        
                        try {
                            range.surroundContents(mark);
                        } catch (e) {
                            console.warn("Could not wrap highlight, range may be invalid.", e);
                        }
                        
                        i = j; // Jump the loop counter past the sequence we just processed.
                    } else {
                        i++; // This word wasn't highlighted, so move to the next one.
                    }
                }
            });
        }
        
             showHighlightNote(highlightId) {
            const highlight = this.highlights.find(h => h.id == highlightId);
            if (highlight && highlight.note) {
                this.showNotification(highlight.note, 'info');
            }
        }

        updateHighlightsList() {
            const $list = $('#highlightsList');
            
            if (this.highlights.length === 0) {
                $list.html('<p>No highlights yet.</p>');
                return;
            }
            
            let html = '<div class="highlights-list">';
            this.highlights.forEach(h => {
                html += `
                    <div class="highlight-item" data-id="${h.id}">
                        <div class="highlight-color" style="background: ${h.color}"></div>
                        <div class="highlight-content">
                            <div class="highlight-text">${this.escapeHtml(h.highlight_text)}</div>
                            ${h.note ? '<div class="highlight-note-text">' + this.escapeHtml(h.note) + '</div>' : ''}
                        </div>
                        <button class="delete-highlight" data-id="${h.id}">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>`;
            });
            html += '</div>';
            
            $list.html(html);
            
            const deleteHandler = (e) => {
                e.stopPropagation();
                this.deleteHighlight($(e.currentTarget).data('id'));
            };
            
            const clickHandler = (e) => {
                const id = $(e.currentTarget).data('id');
                const h = this.highlights.find(hl => hl.id == id);
                if (h && h.start_time) {
                    this.seekTo(h.start_time / 1000);
                }
            };
            
            $list.find('.delete-highlight').on('click', deleteHandler);
            $list.find('.highlight-item').on('click', clickHandler);
        }

        deleteHighlight(id) {
            if (!confirm('Delete this highlight?')) return;
            
            $.post(this.ajaxUrl, {
                action: 'delete_highlight',
                highlight_id: id,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    // Remove from our local array
                    this.highlights = this.highlights.filter(h => h.id != id);
                    
                    // Remove visual highlight
                    this.$transcriptContent.find(`[data-highlight-id="${id}"]`).each(function() {
                        const $elem = $(this);
                        if ($elem.is('mark')) {
                            // For text-based highlights, unwrap the mark
                            const text = $elem.text();
                            $elem.replaceWith(text);
                        } else {
                            // For word-based highlights, remove the styling
                            $elem.removeAttr('data-highlight-id').removeAttr('style');
                        }
                    });
                    
                    this.updateHighlightsList();
                    this.showNotification('Highlight deleted', 'success');
                    this.hasUnsavedChanges = true;
                }
            })
            .fail(() => this.showNotification('Failed to delete', 'error'));
        }
        
        // Translation with speaker names
        setupTranslation() {
            if (this.config.deepl_enabled && Object.keys(this.config.deepl_languages || {}).length > 0) {
                this.$translateContainer.show();
                const languages = this.config.deepl_languages;
                let html = '';
                
                Object.entries(languages).forEach(([code, name]) => {
                    html += `<div class="translate-language-item" data-lang-code="${this.escapeHtml(code)}">${this.escapeHtml(name)}</div>`;
                });
                
                this.$translateLanguageList.html(html);
            }
        }

        handleLanguageSelection(e) {
            this.showTranslationView();
            const targetLang = $(e.currentTarget).data('lang-code');
            this.translateTranscript(targetLang);
            this.$translateLanguageList.removeClass('open');
        }

        showTranslationView() {
            $('#transcript-area-container').addClass('translation-active');
            this.$translationWrapper.show();
            this.$translationContent.html('<div class="loading-state"><p>Select a language.</p></div>');
            this.updateCopyButtonState();
        }

       // REPLACE the entire translateTranscript function with this one:
        translateTranscript(targetLang) {
            if (!targetLang) {
                this.showNotification('Select a language.', 'warning');
                return;
            }
            
            this.$translationContent.html('<div class="loading-state"><div class="spinner"></div><p>Translating...</p></div>');
            
            // Pass the updated speaker map for translation
            $.post(this.ajaxUrl, {
                action: 'translate_transcript',
                transcript_id: this.transcriptId,
                target_lang: targetLang,
                speaker_map: JSON.stringify(this.speakerMap),
                nonce: this.nonce
            })
            .done(res => {
                if (res.success && res.data.translated_utterances) {
                    // --- START NEW LOGIC ---
                    const utterances = res.data.translated_utterances;
                    if (utterances.length === 0) {
                        this.$translationContent.html('<p>No content to translate.</p>');
                        return;
                    }

                    // Get speaker colors (using the original utterances)
                    const speakerColors = this.generateSpeakerColors(this.transcriptData.data.utterances);
                    let html = '';

                    utterances.forEach((utterance, index) => {
                        const originalSpeaker = utterance.speaker || 'A';
                        const displaySpeaker = utterance.display_speaker; // Get display name from server
                        const utteranceTimestamp = this.formatTime(utterance.start / 1000);
                        const textHtml = this.escapeHtml(utterance.text); // Just escape the translated text

                        html += `
                            <div class="utterance-row" data-index="${index}" data-start="${utterance.start}" 
                                 data-end="${utterance.end}" data-original-speaker="${this.escapeHtml(originalSpeaker)}">
                                <div class="utterance-header">
                                    <div class="speaker-info">
                                        <span class="speaker-avatar" data-speaker="${this.escapeHtml(originalSpeaker)}" 
                                              style="background-color: ${speakerColors[originalSpeaker]}">${this.escapeHtml(displaySpeaker.charAt(0).toUpperCase())}</span>
                                        <span class="speaker-label" data-speaker="${this.escapeHtml(originalSpeaker)}">
                                            ${this.escapeHtml(displaySpeaker)}
                                        </span>
                                    </div>
                                    <span class="utterance-timestamp">${this.escapeHtml(utteranceTimestamp)}</span>
                                </div>
                                <p class="utterance-text" contenteditable="false">${textHtml.trim()}</p>
                            </div>`;
                    });
                    
                    this.$translationContent.html(html);
                    // --- END NEW LOGIC ---

                } else {
                    this.$translationContent.html(`<div class="error-message">${this.escapeHtml(res.data || 'Translation failed')}</div>`);
                }

                this.updateCopyButtonState();
            })
            .fail(() => {
                this.$translationContent.html('<div class="error-message">Network error.</div>');
                this.updateCopyButtonState();
            });
        }

        hideTranslation() {
            this.$translationWrapper.hide();
            $('#transcript-area-container').removeClass('translation-active');
            this.$translateLanguageList.removeClass('open');
            this.updateCopyButtonState();
        }

        // Export with title as filename
        showExportModal() {
            const modalHtml = `
                <div class="modal" id="exportModal" style="display:none;">
                    <div class="modal-content modal-export-enhanced">
                        <div class="modal-header">
                            <h3>Export Transcript</h3>
                            <button class="modal-close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="export-section">
                                <h4>1. Select Format</h4>
                                <div class="export-options">
                                    <button class="export-option" data-export-format="txt">
                                        <span class="material-symbols-outlined">description</span>
                                        <span>Text</span><small>.txt</small>
                                    </button>
                                    <button class="export-option" data-export-format="docx">
                                        <span class="material-symbols-outlined">article</span>
                                        <span>Word</span><small>.docx</small>
                                    </button>
                                    <button class="export-option" data-export-format="pdf">
                                        <span class="material-symbols-outlined">picture_as_pdf</span>
                                        <span>PDF</span><small>.pdf</small>
                                    </button>
                                    <button class="export-option" data-export-format="srt">
                                        <span class="material-symbols-outlined">subtitles</span>
                                        <span>SRT</span><small>Subtitles</small>
                                    </button>
                                    <button class="export-option" data-export-format="vtt">
                                        <span class="material-symbols-outlined">closed_caption</span>
                                        <span>VTT</span><small>Captions</small>
                                    </button>
                                    <button class="export-option" data-export-format="json">
                                        <span class="material-symbols-outlined">code</span>
                                        <span>JSON</span><small>Raw Data</small>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="export-section export-options-section" style="display: none;">
                                <h4>2. Configure Options</h4>
                                <div class="export-settings">
                                    <label class="export-checkbox">
                                        <input type="checkbox" id="includeTimestamps" value="true">
                                        <span>Include timestamps</span>
                                    </label>
                                    <label class="export-checkbox">
                                        <input type="checkbox" id="includeSpeakers" value="true" checked>
                                        <span>Include speaker names</span>
                                    </label>
                                    <label class="export-checkbox" id="includeHighlightsLabel" style="display: none;">
                                        <input type="checkbox" id="includeHighlights" value="true" checked>
                                        <span>Include highlights (DOCX/PDF only)</span>
                                    </label>
                                    <div class="export-radio-group">
                                        <label>Paragraph Formatting:</label>
                                        <label class="export-radio">
                                            <input type="radio" name="paragraphMode" value="utterance" checked>
                                            <span>New paragraph for each entry</span>
                                        </label>
                                        <label class="export-radio">
                                            <input type="radio" name="paragraphMode" value="speaker">
                                            <span>Group paragraphs by speaker</span>
                                        </label>
                                        <label class="export-radio">
                                            <input type="radio" name="paragraphMode" value="continuous">
                                            <span>One continuous block of text</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="export-actions" style="display: none;">
                                <button id="confirmExportBtn">
                                    <span class="material-symbols-outlined">download</span> Export Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            
            $('#exportModal').remove();
            $('body').append(modalHtml);
            
            const $modal = $('#exportModal');
            $modal.fadeIn(200);

            $modal.on('click', '.export-option', function() {
                const $this = $(this);
                $modal.find('.export-option').removeClass('selected');
                $this.addClass('selected');
                
                const format = $this.data('export-format');
                const $optionsSection = $modal.find('.export-options-section');
                const $highlightsOption = $modal.find('#includeHighlightsLabel');
                
                if (['txt', 'docx', 'pdf'].includes(format)) {
                    $optionsSection.slideDown(200);
                    if (['docx', 'pdf'].includes(format)) {
                        $highlightsOption.slideDown(100);
                    } else {
                        $highlightsOption.slideUp(100);
                    }
                } else {
                    $optionsSection.slideUp(200);
                    $highlightsOption.slideUp(100);
                }
                $modal.find('.export-actions').slideDown(200);
            });
            
            $modal.on('click', '#confirmExportBtn', () => {
                const format = $modal.find('.export-option.selected').data('export-format');
                if (format) {
                    this.exportTranscript(format);
                } else {
                    this.showNotification("Select a format first.", "warning");
                }
            });
        }

        exportTranscript(format) {
            if (this.isEditing && this.hasUnsavedChanges) {
                if (!confirm("You have unsaved changes. Export will use the last saved version. Save changes first?")) {
                    return;
                }
            }
            
            const $modal = $('#exportModal');
            const includeTimestamps = $modal.find('#includeTimestamps').is(':checked');
            const includeSpeakers = $modal.find('#includeSpeakers').is(':checked');
            const includeHighlights = $modal.find('#includeHighlights').is(':checked');
            const paragraphMode = $modal.find('input[name="paragraphMode"]:checked').val() || 'utterance';
            
            // Apply speaker map to export data
            const exportData = JSON.parse(JSON.stringify(this.transcriptData.data));
            if (exportData.utterances) {
                exportData.utterances.forEach(utterance => {
                    if (utterance.speaker && this.speakerMap[utterance.speaker]) {
                        utterance.speaker = this.speakerMap[utterance.speaker];
                    }
                });
            }
            
            this.showLoading(true);
            
            $.post(this.ajaxUrl, {
                action: 'export_transcript',
                transcript_id: this.transcriptId,
                format: format,
                include_timestamps: includeTimestamps,
                include_speakers: includeSpeakers,
                include_highlights: includeHighlights,
                paragraph_mode: paragraphMode,
                transcript_data: JSON.stringify(exportData),
                title: this.transcriptData.title, // Pass title for filename
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    if (res.data.is_download_url) {
                        window.location.href = res.data.download_url;
                    } else {
                        this.downloadFile(res.data.content, res.data.filename);
                    }
                    this.showNotification(res.data.message || 'Export successful!', 'success');
                } else {
                    this.showNotification('Export failed: ' + (res.data || 'Unknown error'), 'error');
                }
            })
            .fail(() => this.showNotification('Network error.', 'error'))
            .always(() => {
                this.showLoading(false);
                $modal.fadeOut(200, () => $modal.remove());
            });
        }

        downloadFile(content, filename) {
            const a = document.createElement('a');
            const type = filename.endsWith('.html') ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8';
            const blob = new Blob([content], { type });
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        }

        confirmDelete() {
            if (!confirm('Permanently delete this transcript? This cannot be undone.')) return;
            
            this.showLoading(true);
            
            $.post(this.ajaxUrl, {
                action: 'delete_transcript',
                transcript_id: this.transcriptId,
                nonce: this.nonce
            })
            .done(res => {
                if (res.success) {
                    this.showNotification('Transcript deleted. Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = '/my-transcripts/';
                    }, 1500);
                } else {
                    this.showLoading(false);
                    this.showNotification(res.data || 'Delete failed.', 'error');
                }
            })
            .fail(() => {
                this.showLoading(false);
                this.showNotification('Network error.', 'error');
            });
        }

        // Helpers
        generateSpeakerColors(utterances) {
            const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#d946ef', '#14b8a6'];
            const speakers = {};
            let i = 0;
            
            utterances.forEach(u => {
                if (u.speaker && !speakers[u.speaker]) {
                    speakers[u.speaker] = colors[i++ % colors.length];
                }
            });
            
            return speakers;
        }

        formatTime(seconds) {
            const totalSeconds = Math.floor(seconds);
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const secs = totalSeconds % 60;
            
            if (hours > 0) {
                return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            }
            return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        escapeHtml(text) {
            return (text || '').toString().replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[m]);
        }
// REPLACE your entire showLoading function with this one:

        showLoading(show) {
            // Always clear any previous timer, just in case.
            clearTimeout(this.loadingTimer);

            if (show) {
                // Show the overlay.
                $('#loadingOverlay').remove();
                $('body').append('<div id="loadingOverlay"><div class="spinner"></div></div>');
                
                // Store the new timer in our class property.
                this.loadingTimer = setTimeout(() => {
                    $('#loadingOverlay').remove();
                    this.showNotification('Loading timed out. Please refresh the page.', 'error');
                }, 30000); // 30-second failsafe
            } else {
                // If we are hiding the loading screen, it means the operation
                // finished, so we must also remove the overlay.
                $('#loadingOverlay').remove();
            }
        }
        showError(msg) {
            this.$transcriptContent.html(`<div class="error-message">${this.escapeHtml(msg)}</div>`);
        }

        showNotification(message, type = 'info') {
            const $note = $(`
                <div class="notification notification-${type}">
                    ${this.escapeHtml(message)}
                    <button class="notification-close">&times;</button>
                </div>
            `).appendTo('body');
            
            setTimeout(() => $note.addClass('show'), 10);
            
            $note.find('.notification-close').on('click', () => {
                $note.removeClass('show');
                setTimeout(() => $note.remove(), 300);
            });
            
            const timeout = type === 'error' ? 10000 : 5000;
            setTimeout(() => {
                $note.removeClass('show');
                setTimeout(() => $note.remove(), 300);
            }, timeout);
        }
    }

    // Initialize
    $(document).ready(() => {
        if ($('#transcript-content').length) {
            window.transcriptViewerInstance = new TranscriptViewer();
        }
    });

})(jQuery);