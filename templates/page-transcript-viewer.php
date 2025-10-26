<?php
/**
 * Template Name: Transcript Viewer
 * This template is now included via a shortcode.
 * All redirect and permission logic has been moved to the main plugin file.
 */
?>

<div class="transcribe-ai-viewer-wrapper">
    <div class="transcribe-ai-viewer">
        
        <div class="viewer-header">
            <div class="header-content">
                <div class="transcript-info">
                    <h1 id="transcript-title" class="editable-title" contenteditable="false" title="Click to edit title">
                        <?php _e('Loading...', 'transcribe-ai'); ?>
                    </h1>
                    <div class="transcript-meta">
                        <span id="transcript-date"></span>
                    </div>
                </div>
                <div class="header-actions">
                    <?php if (is_user_logged_in()): ?>
                        <a href="<?php echo esc_url(home_url('/my-transcripts/')); ?>" class="btn-back">
                            <span class="material-symbols-outlined">arrow_back</span>
                            <?php _e('Back to List', 'transcribe-ai'); ?>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo esc_url(home_url('/transcribe/')); ?>" class="btn-back">
                            <span class="material-symbols-outlined">arrow_back</span>
                            <?php _e('New Transcript', 'transcribe-ai'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="sticky-container">
            <div class="audio-player-container">
                <audio id="audioPlayer" style="display: none;" preload="metadata"></audio>
                <div class="player-controls">
                    <button id="rewindBtn" class="player-btn" title="<?php esc_attr_e('Rewind 10s (Left Arrow)', 'transcribe-ai'); ?>" aria-label="<?php esc_attr_e('Rewind 10 seconds', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">replay_10</span>
                    </button>
                    <button id="playPauseBtn" class="player-btn" title="<?php esc_attr_e('Play/Pause (Spacebar)', 'transcribe-ai'); ?>" aria-label="<?php esc_attr_e('Play or pause audio', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">play_arrow</span>
                    </button>
                    <button id="forwardBtn" class="player-btn" title="<?php esc_attr_e('Forward 10s (Right Arrow)', 'transcribe-ai'); ?>" aria-label="<?php esc_attr_e('Forward 10 seconds', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">forward_10</span>
                    </button>
                </div>
                <span id="currentTime" class="player-time" aria-label="<?php esc_attr_e('Current time', 'transcribe-ai'); ?>">00:00</span>
                <input type="range" id="progressBar" class="progress-slider" value="0" min="0" max="100" step="0.1" aria-label="<?php esc_attr_e('Audio progress', 'transcribe-ai'); ?>">
                <span id="totalTime" class="player-time" aria-label="<?php esc_attr_e('Total duration', 'transcribe-ai'); ?>">00:00</span>
                <button id="speedBtn" class="player-btn speed-btn" title="<?php esc_attr_e('Playback Speed', 'transcribe-ai'); ?>" aria-label="<?php esc_attr_e('Change playback speed', 'transcribe-ai'); ?>">1x</button>
            </div>
            
            <div class="control-bar">
                <div class="control-group">
                    <button id="editBtn" class="control-btn" aria-label="<?php esc_attr_e('Toggle edit mode', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">edit</span>
                        <?php _e('Edit', 'transcribe-ai'); ?>
                    </button>
                    
                   <div id="searchWrapper" class="control-dropdown-container">
    <button id="searchBtn" class="control-btn" aria-label="<?php esc_attr_e('Search transcript', 'transcribe-ai'); ?>" aria-haspopup="true" aria-expanded="false">
        <span class="material-symbols-outlined">search</span>
        <?php _e('Search', 'transcribe-ai'); ?>
    </button>
    <div id="searchBox" class="control-dropdown-menu search-dropdown">
        <div class="find-container">
            <input type="text" id="searchInput" placeholder="<?php esc_attr_e('Find in transcript...', 'transcribe-ai'); ?>" aria-label="Search input">
            <div class="search-nav">
                <span id="searchCounter" aria-live="polite"></span>
                <button id="searchPrev" title="<?php esc_attr_e('Previous (Shift+Enter)', 'transcribe-ai'); ?>" aria-label="Previous result">
                    <span class="material-symbols-outlined">keyboard_arrow_up</span>
                </button>
                <button id="searchNext" title="<?php esc_attr_e('Next (Enter)', 'transcribe-ai'); ?>" aria-label="Next result">
                    <span class="material-symbols-outlined">keyboard_arrow_down</span>
                </button>
            </div>
            <div class="search-options-wrapper">
                <button id="searchOptionsBtn" class="search-options-btn" title="<?php esc_attr_e('Search options', 'transcribe-ai'); ?>">
                    <span class="material-symbols-outlined">more_vert</span>
                </button>
                <div id="searchOptionsMenu" class="control-dropdown-menu">
                    <div class="search-option" data-option="matchCase">
                        <span class="material-symbols-outlined">check_box_outline_blank</span>
                        <span><?php _e('Match Case', 'transcribe-ai'); ?></span>
                    </div>
                    <div class="search-option" data-option="findAndReplace">
                        <span class="material-symbols-outlined">check_box_outline_blank</span>
                        <span><?php _e('Find & Replace', 'transcribe-ai'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div id="replaceContainer" class="replace-container" style="display: none;">
            <input type="text" id="replaceInput" placeholder="<?php esc_attr_e('Replace with...', 'transcribe-ai'); ?>">
            <button id="replaceAllBtn" title="<?php esc_attr_e('Replace all occurrences', 'transcribe-ai'); ?>">
                <span class="material-symbols-outlined">find_replace</span>
                <span><?php _e('Replace All', 'transcribe-ai'); ?></span>
            </button>
        </div>
    </div>
</div>
                    
                    <button id="summaryBtn" class="control-btn" aria-label="<?php esc_attr_e('View summary', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">summarize</span>
                        <?php _e('Summary', 'transcribe-ai'); ?>
                    </button>
                    
                    <button id="highlightBtn" class="control-btn" aria-label="<?php esc_attr_e('Toggle highlight mode', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">highlight</span>
                        <?php _e('Highlights', 'transcribe-ai'); ?>
                    </button>
                    
                    <div id="translate-container" class="translate-dropdown-container" style="display: none;">
                        <button id="translateDropdownBtn" class="control-btn" aria-label="<?php esc_attr_e('Translate transcript', 'transcribe-ai'); ?>" aria-expanded="false" aria-haspopup="true">
                            <span class="material-symbols-outlined">translate</span>
                            <span><?php _e('Translate', 'transcribe-ai'); ?></span>
                            <span class="material-symbols-outlined dropdown-arrow">arrow_drop_down</span>
                        </button>
                        <div id="translate-language-list" class="translate-language-list" role="menu" aria-label="<?php esc_attr_e('Select translation language', 'transcribe-ai'); ?>">
                        </div>
                    </div>
                    
                    <button id="exportBtn" class="control-btn" aria-label="<?php esc_attr_e('Export transcript', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">download</span>
                        <?php _e('Export', 'transcribe-ai'); ?>
                    </button>
                    
                    <div id="timestampsDropdown" class="control-dropdown-container">
                        <button id="timestampsBtn" class="control-btn" aria-label="<?php esc_attr_e('Change timestamp view', 'transcribe-ai'); ?>" aria-haspopup="true" aria-expanded="false">
                            <span class="material-symbols-outlined">schedule</span>
                            <span id="timestampsBtnLabel"><?php _e('Timestamps', 'transcribe-ai'); ?></span>
                            <span class="material-symbols-outlined dropdown-arrow">arrow_drop_down</span>
                        </button>
                        <div id="timestampsMenu" class="control-dropdown-menu">
                            <div class="timestamps-option" data-mode="utterance"><?php _e('Per Utterance', 'transcribe-ai'); ?></div>
                            <div class="timestamps-option" data-mode="sentence"><?php _e('Per Sentence', 'transcribe-ai'); ?></div>
                            <div class="timestamps-option" data-mode="none"><?php _e('Hide All', 'transcribe-ai'); ?></div>
                        </div>
                    </div>

                    <div id="copyDropdown" class="control-dropdown-container copy-dropdown-container">
                        <button id="copyTranscriptBtn" type="button" class="control-btn" aria-label="<?php esc_attr_e('Copy transcript to clipboard', 'transcribe-ai'); ?>">
                            <span class="material-symbols-outlined">save</span>
                            <span><?php _e('Copy', 'transcribe-ai'); ?></span>
                            <span class="material-symbols-outlined dropdown-arrow" aria-hidden="true">arrow_drop_down</span>
                        </button>
                        <div id="copyMenu" class="control-dropdown-menu" role="menu" aria-label="<?php esc_attr_e('Copy options', 'transcribe-ai'); ?>">
                            <div class="copy-option" data-target="original" role="menuitem">
                                <span class="material-symbols-outlined">content_copy</span>
                                <span><?php _e('Copy Original', 'transcribe-ai'); ?></span>
                            </div>
                            <div class="copy-option translation-option" data-target="translation" role="menuitem">
                                <span class="material-symbols-outlined">translate</span>
                                <span><?php _e('Copy Translation', 'transcribe-ai'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="control-group">
                    <button id="deleteBtn" class="control-btn control-btn-danger" aria-label="<?php esc_attr_e('Delete transcript', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">delete</span>
                        <?php _e('Delete', 'transcribe-ai'); ?>
                    </button>
                </div> </div> </div> <div id="summaryBox" style="display: none;">
            <div id="summaryContent">
                <div class="loading-state">
                    <p><?php _e('Click to load summary...', 'transcribe-ai'); ?></p>
                </div>
            </div>
        </div>
        
        <div id="highlightBox" style="display: none;">
            <div id="highlightsList">
                <p><?php _e('Your highlights will appear here...', 'transcribe-ai'); ?></p>
            </div>
        </div>
        
        <div id="transcript-area-container">
            <div id="original-content-wrapper">
                 <div class="transcript-header">
                    <h3><?php _e('Original Transcript', 'transcribe-ai'); ?></h3>
                </div>
                <div id="transcript-content" class="transcript-content" role="main" aria-live="polite" aria-label="<?php esc_attr_e('Transcript content', 'transcribe-ai'); ?>">
                    <div class="loading-state">
                        <div class="spinner" role="status" aria-label="<?php esc_attr_e('Loading', 'transcribe-ai'); ?>"></div>
                        <p><?php _e('Loading transcript...', 'transcribe-ai'); ?></p>
                    </div>
                </div>
            </div>
            
            <div id="translation-content-wrapper" style="display: none;">
                <div class="translation-header">
                    <h3><?php _e('Translation', 'transcribe-ai'); ?></h3>
                    <button id="closeTranslationBtn" class="control-btn" aria-label="<?php esc_attr_e('Close translation', 'transcribe-ai'); ?>">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div id="translation-content" class="transcript-content" contenteditable="false" role="region" aria-live="polite" aria-label="<?php esc_attr_e('Translation content', 'transcribe-ai'); ?>">
                     <div class="loading-state">
                        <div class="spinner" role="status" aria-label="<?php esc_attr_e('Loading', 'transcribe-ai'); ?>"></div>
                        <p><?php _e('Translating...', 'transcribe-ai'); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div aria-live="polite" aria-atomic="true" class="sr-only" id="announcements"></div>

<style>
/* Base styles are in the main CSS file. These are overrides or template-specific. */
body {
    overflow-y: scroll; /* Prevent layout shift when modals appear */
}
/* ... The rest of your existing <style> block remains the same ... */
</style>

<script>
// Enhanced accessibility and error handling
document.addEventListener('DOMContentLoaded', function() {
     // Screen reader announcements
    function announce(message) {
        const announcer = document.getElementById('announcements');
        if (announcer) {
            announcer.textContent = message;
            setTimeout(() => announcer.textContent = '', 1000);
        }
    }
    
    // Add announcement function to global scope for the viewer script
    window.transcribeAnnounce = announce;
    
    // Enhanced keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.isContentEditable) {
            return;
        }
        // ... switch statement for keyboard shortcuts ...
        
        switch(e.key) {
            // ... cases for F, E, S, H ...

            case 'Escape':
                e.preventDefault();
                // Close any open modals
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display !== 'none') {
                        const closeBtn = modal.querySelector('.modal-close');
                        if (closeBtn) closeBtn.click();
                    }
                });

                // Close any open control dropdowns
                const openMenus = document.querySelectorAll('.control-dropdown-menu.open');
                openMenus.forEach(menu => {
                    menu.classList.remove('open');
                    // Also update the aria-expanded attribute on the button that triggered it
                    const trigger = menu.closest('.control-dropdown-container').querySelector('button[aria-expanded="true"]');
                    if(trigger) trigger.setAttribute('aria-expanded', 'false');
                });
                
                // Close summary box
                const summaryBox = document.getElementById('summaryBox');
                if (summaryBox && summaryBox.style.display !== 'none') {
                    const summaryBtn = document.getElementById('summaryBtn');
                    if (summaryBtn) summaryBtn.click();
                }
                
                // Close highlight box
                const highlightBox = document.getElementById('highlightBox');
                if (highlightBox && highlightBox.style.display !== 'none') {
                    const highlightBtn = document.getElementById('highlightBtn');
                    if (highlightBtn) highlightBtn.click();
                }
                break;
        }
    });
        
    // Error boundary for the transcript viewer
    window.addEventListener('error', function(e) {
        console.error('Transcript viewer error:', e.error);
        announce('<?php echo esc_js(__('An error occurred. Please refresh the page.', 'transcribe-ai')); ?>');
    });
    
    // Handle connection errors
    window.addEventListener('online', function() {
        announce('<?php echo esc_js(__('Connection restored', 'transcribe-ai')); ?>');
    });
    
    window.addEventListener('offline', function() {
        announce('<?php echo esc_js(__('Connection lost. Some features may not work.', 'transcribe-ai')); ?>');
    });
    
    // Prevent accidental page navigation
    let hasUnsavedChanges = false;
    
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = '<?php echo esc_js(__('You have unsaved changes. Are you sure you want to leave?', 'transcribe-ai')); ?>';
            return e.returnValue;
        }
    });
    
    // Track unsaved changes
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('utterance-text') && e.target.isContentEditable) {
            hasUnsavedChanges = true;
        }
    });
    
    // Clear unsaved changes flag when saved
    document.addEventListener('transcriptSaved', function() {
        hasUnsavedChanges = false;
    });
});
</script>

