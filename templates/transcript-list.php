<?php
/**
 * Transcribe AI - Transcript List Template - FIXED VERSION WITH PAGINATION
 * 
 * This template displays the user's transcript list with pagination
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Get viewer page URL
$viewer_page = get_page_by_path('transcript-viewer');
$viewer_url = $viewer_page ? get_permalink($viewer_page->ID) : '#';

// Pagination setup - We now use the $transcript_query object from render_list
$paged_transcripts = $transcript_query->posts; // Get the posts for this page
$total_transcripts = $transcript_query->found_posts; // Get the total number of posts
$total_pages = $transcript_query->max_num_pages; // Get the total number of pages
$current_page = max(1, get_query_var('paged')); // Get the current page number
$per_page = $transcript_query->query_vars['posts_per_page']; // Get posts_per_page from the query
$offset = ($current_page - 1) * $per_page;

// Calculate statistics
$total_duration = 0;
$recent_transcripts = [];

foreach ($transcripts as $transcript) {
    $data = get_post_meta($transcript->ID, '_transcript_data', true);
    if (isset($data['audio_duration'])) {
        $total_duration += $data['audio_duration'];
    }
    
    // Get transcripts from last 7 days
    $post_date = strtotime($transcript->post_date);
    if ($post_date > strtotime('-7 days')) {
        $recent_transcripts[] = $transcript;
    }
}
?>

<div class="transcripts-list-container">
    
    <!-- Header Section -->
    <div class="list-header">
        <h2 class="list-title">
            <?php _e('My Transcripts', 'transcribe-ai'); ?>
        </h2>
        <div class="list-actions">
            <a href="<?php echo home_url('/transcribe/'); ?>" class="btn btn-primary">
                <span class="material-symbols-outlined">add</span>
                <?php _e('New Transcript', 'transcribe-ai'); ?>
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <span class="material-symbols-outlined">description</span>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($total_transcripts); ?></div>
                <div class="stat-label"><?php _e('Total Transcripts', 'transcribe-ai'); ?></div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <span class="material-symbols-outlined">schedule</span>
            </div>
            <div class="stat-content">
                <div class="stat-value">
                    <?php echo Transcribe_AI_Helpers::format_duration($total_duration); ?>
                </div>
                <div class="stat-label"><?php _e('Total Duration', 'transcribe-ai'); ?></div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <span class="material-symbols-outlined">trending_up</span>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($recent_transcripts); ?></div>
                <div class="stat-label"><?php _e('This Week', 'transcribe-ai'); ?></div>
            </div>
        </div>
    </div>
    
    <?php if (empty($transcripts)): ?>
        
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-icon">
                <span class="material-symbols-outlined">folder_open</span>
            </div>
            <h3 class="empty-title">
                <?php _e('No transcripts yet', 'transcribe-ai'); ?>
            </h3>
            <p class="empty-message">
                <?php _e('Upload your first audio file to get started with AI transcription.', 'transcribe-ai'); ?>
            </p>
            <a href="<?php echo home_url('/transcribe/'); ?>" class="btn btn-primary">
                <span class="material-symbols-outlined">upload</span>
                <?php _e('Upload Audio File', 'transcribe-ai'); ?>
            </a>
        </div>
        
    <?php else: ?>
        
        <!-- Transcripts Table -->
        <div class="transcripts-table-wrapper">
            <table class="transcripts-table">
                <thead>
                    <tr>
                        <th><?php _e('Title', 'transcribe-ai'); ?></th>
                        <th><?php _e('Date', 'transcribe-ai'); ?></th>
                        <th><?php _e('Duration', 'transcribe-ai'); ?></th>
                        <th><?php _e('Speakers', 'transcribe-ai'); ?></th>
                        <th><?php _e('Actions', 'transcribe-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paged_transcripts as $transcript): 
                        $transcript_data = get_post_meta($transcript->ID, '_transcript_data', true);
                        $duration = isset($transcript_data['audio_duration']) ? $transcript_data['audio_duration'] : 0;
                        $speakers = 0;
                        
                        if (isset($transcript_data['utterances'])) {
                            $speaker_list = array_unique(array_column($transcript_data['utterances'], 'speaker'));
                            $speakers = count($speaker_list);
                        }
                    ?>
                        <tr>
                            <td class="title-cell">
                                <a href="<?php echo add_query_arg('id', $transcript->ID, $viewer_url); ?>" class="transcript-link">
                                    <?php echo esc_html(str_replace('Private: ', '', get_the_title($transcript))); ?>
                                </a>
                            </td>
                            <td class="date-cell">
                                <?php echo get_the_date('M j, Y', $transcript); ?>
                                <span class="time-ago">
                                    <?php echo human_time_diff(get_the_time('U', $transcript), current_time('timestamp')) . ' ago'; ?>
                                </span>
                            </td>
                            <td class="duration-cell">
                                <?php echo Transcribe_AI_Helpers::format_duration($duration); ?>
                            </td>
                            <td class="speakers-cell">
                                <?php if ($speakers > 0): ?>
                                    <span class="speaker-count">
                                        <?php echo $speakers; ?> <?php echo _n('speaker', 'speakers', $speakers, 'transcribe-ai'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <a href="<?php echo add_query_arg('id', $transcript->ID, $viewer_url); ?>" 
                                       class="action-btn view-btn" 
                                       title="<?php esc_attr_e('View', 'transcribe-ai'); ?>">
                                        <span class="material-symbols-outlined">visibility</span>
                                    </a>
                                    <a href="<?php echo add_query_arg(['id' => $transcript->ID, '#export' => ''], $viewer_url); ?>" 
                                       class="action-btn download-btn" 
                                       title="<?php esc_attr_e('Export/Download', 'transcribe-ai'); ?>">
                                        <span class="material-symbols-outlined">download</span>
                                    </a>
                                    <button class="action-btn delete-btn" 
                                            data-id="<?php echo $transcript->ID; ?>"
                                            data-title="<?php echo esc_attr(get_the_title($transcript)); ?>"
                                            title="<?php esc_attr_e('Delete', 'transcribe-ai'); ?>">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrapper">
            <div class="pagination">
                <?php
                // This creates a base URL that preserves other query args (like sorting, etc.)
                global $wp;
                $base_url = remove_query_arg('paged', home_url(add_query_arg(array(), $wp->request)));

                // Previous button
                if ($current_page > 1): ?>
                    <a href="<?php echo add_query_arg('paged', $current_page - 1, $base_url); ?>" class="pagination-prev">
                        <span class="material-symbols-outlined">chevron_left</span>
                        <?php _e('Previous', 'transcribe-ai'); ?>
                    </a>
                <?php endif; ?>
                
                <!-- Page numbers -->
                <div class="pagination-numbers">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="page-num current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo add_query_arg('paged', $i, $base_url); ?>" class="page-num">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                
                <!-- Next button -->
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo add_query_arg('paged', $current_page + 1, $base_url); ?>" class="pagination-next">
                        <?php _e('Next', 'transcribe-ai'); ?>
                        <span class="material-symbols-outlined">chevron_right</span>
                    </a>
                <?php endif; ?>
            </div>
            
            <!-- Page info -->
            <div class="pagination-info">
                <?php printf(
                    __('Showing %d-%d of %d transcripts', 'transcribe-ai'),
                    $offset + 1,
                    min($offset + $per_page, $total_transcripts),
                    $total_transcripts
                ); ?>
            </div>
        </div>
        <?php endif; ?>
        
    <?php endif; ?>
</div>

<style>
.transcripts-list-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

.list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.list-title {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    margin: 0;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: 0.5rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 48px;
    height: 48px;
    background: #eff6ff;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3b82f6;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827;
}

.stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-top: 0.25rem;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
}

.empty-icon {
    width: 80px;
    height: 80px;
    background: #f3f4f6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    color: #6b7280;
}

.empty-icon .material-symbols-outlined {
    font-size: 2.5rem;
}

.empty-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 0.5rem;
}

.empty-message {
    color: #6b7280;
    margin: 0 0 2rem;
}

.transcripts-table-wrapper {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    overflow: hidden;
    margin-bottom: 2rem;
}

.transcripts-table {
    width: 100%;
    border-collapse: collapse;
}

.transcripts-table thead {
    background: #f9fafb;
}

.transcripts-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
    border-bottom: 1px solid #e5e7eb;
}

.transcripts-table td {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
}

.transcripts-table tbody tr:last-child td {
    border-bottom: none;
}

.transcripts-table tbody tr:hover {
    background: #f9fafb;
}

.transcript-link {
    color: #111827;
    font-weight: 500;
    text-decoration: none;
}

.transcript-link:hover {
    color: #3b82f6;
    text-decoration: underline;
}

.time-ago {
    display: block;
    font-size: 0.75rem;
    color: #9ca3af;
    margin-top: 0.25rem;
}

.speaker-count {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: #eff6ff;
    color: #3b82f6;
    border-radius: 999px;
    font-size: 0.875rem;
}

.text-muted {
    color: #9ca3af;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.action-btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e5e7eb;
    border-radius: 0.375rem;
    background: white;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.action-btn:hover {
    background: #f3f4f6;
    color: #111827;
}

.action-btn.delete-btn:hover {
    background: #fee2e2;
    color: #ef4444;
    border-color: #fecaca;
}

/* Pagination Styles */
.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pagination-prev,
.pagination-next {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 1rem;
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    color: #374151;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s;
}

.pagination-prev:hover,
.pagination-next:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.pagination-numbers {
    display: flex;
    gap: 0.25rem;
}

.page-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    color: #374151;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s;
}

.page-num:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.page-num.current {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.pagination-info {
    font-size: 0.875rem;
    color: #6b7280;
}

@media (max-width: 768px) {
    .transcripts-table-wrapper {
        overflow-x: auto;
    }
    
    .transcripts-table {
        min-width: 600px;
    }
    
    .list-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .pagination-wrapper {
        flex-direction: column;
        gap: 1rem;
    }
    
    .pagination-numbers {
        display: none;
    }
}
</style>

<!-- Fixed JavaScript with working delete and proper export redirect -->
<script>
jQuery(document).ready(function($) {
// Replace the $('.delete-btn').on('click') block
$('.delete-btn').on('click', function(e) {
    e.preventDefault();
    const $this = $(this);
    const transcriptId = $this.data('id');
    const transcriptTitle = $this.data('title');
    
    if (!confirm('Are you sure you want to delete "' + transcriptTitle + '"? This action cannot be undone.')) {
        return;
    }
    
    $this.prop('disabled', true);
    const $row = $this.closest('tr');
    $this.html('<span class="material-symbols-outlined">hourglass_empty</span>');
    
    deleteTranscript(transcriptId, 0);  // Start with tryCount 0
    
    function deleteTranscript(id, tryCount) {
        if (tryCount >= 3) {
            showNotification('Failed to delete after 3 attempts. Please try again later.', 'error');
            $this.prop('disabled', false);
            $this.html('<span class="material-symbols-outlined">delete</span>');
            return;
        }
        
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'delete_transcript',
                transcript_id: id,
                nonce: '<?php echo wp_create_nonce('transcribe_ai_nonce'); ?>'  // Refresh nonce if needed via separate call
            },
            success: function(response) {
                if (response.success) {
                    $row.css('background-color', '#fee2e2').fadeOut(400, function() {
                        $(this).remove();
                        if ($('.transcripts-table tbody tr').length === 0) {
                            location.reload();
                        }
                        showNotification('Transcript deleted successfully', 'success');
                    });
                } else {
                    showNotification('Failed: ' + (response.data || 'Unknown error'), 'error');
                    $this.prop('disabled', false);
                    $this.html('<span class="material-symbols-outlined">delete</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Delete error:', xhr, status, error);
                showNotification('Network error. Retrying... (' + (tryCount + 1) + '/3)', 'warning');
                setTimeout(() => deleteTranscript(id, tryCount + 1), 2000);  // Retry after 2s
            }
        });
    }
});
    
    // Simple notification function
    function showNotification(message, type) {
        const typeClasses = {
            'success': 'background: #10b981; color: white;',
            'error': 'background: #ef4444; color: white;',
            'info': 'background: #3b82f6; color: white;'
        };
        
        const notification = $('<div>')
            .attr('style', 'position: fixed; bottom: 20px; right: 20px; padding: 1rem 1.5rem; border-radius: 8px; z-index: 1000; ' + (typeClasses[type] || typeClasses.info))
            .text(message)
            .appendTo('body')
            .hide()
            .fadeIn(300);
        
        setTimeout(function() {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
});
</script>