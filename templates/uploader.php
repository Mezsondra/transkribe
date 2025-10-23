<?php
/**
 * Transcribe AI - Uploader Template with Language Selection - FIXED VERSION
 * 
 * This template renders the file upload interface
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

$user_data = Transcribe_AI_Helpers::get_user_data();
$languages = Transcribe_AI_Helpers::get_supported_languages();
?>

<script src="https://cdn.tailwindcss.com"></script>

<div id="transcribe-ai-container" class="bg-gray-50 min-h-screen py-8">
    <div class="max-w-4xl mx-auto px-4">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-2">
                <?php _e('Audio Transcription', 'transcribe-ai'); ?>
            </h1>
            <p class="text-lg text-gray-600">
                <?php _e('Upload your audio or video file to get an accurate AI-powered transcript', 'transcribe-ai'); ?>
            </p>
        </div>
        
        <!-- Upload Card -->
        <div id="upload-view" class="bg-white rounded-xl shadow-lg overflow-hidden">
            
            <!-- Upload Area -->
            <div class="p-8">
                <div id="drop-zone" class="border-2 border-dashed border-gray-300 rounded-xl p-12 text-center hover:border-blue-500 transition-colors cursor-pointer group">
                    <div class="pointer-events-none">
                        <!-- Icon -->
                        <div class="mx-auto w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center group-hover:bg-blue-100 transition-colors mb-4">
                            <span class="material-symbols-outlined text-blue-600 text-4xl">cloud_upload</span>
                        </div>
                        
                        <!-- Text -->
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">
                            <?php _e('Drop your file here', 'transcribe-ai'); ?>
                        </h3>
                        <p class="text-gray-500 mb-4">
                            <?php _e('or click to browse', 'transcribe-ai'); ?>
                        </p>
                        
                        <!-- Button -->
                        <button type="button" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                            <span class="material-symbols-outlined mr-2">folder_open</span>
                            <?php _e('Select File', 'transcribe-ai'); ?>
                        </button>
                        
                        <!-- File types -->
                        <p class="text-sm text-gray-400 mt-4">
                            <?php _e('Supported formats: MP3, WAV, M4A, MP4, OGG, WebM (Max 500MB)', 'transcribe-ai'); ?>
                        </p>
                    </div>
                </div>
                
                <!-- Hidden file input -->
                <input type="file" id="audioFile" class="hidden" accept="audio/*,video/*">
                
                <!-- File info display -->
                <div id="file-info" class="mt-6" style="display: none;"></div>
                
                <!-- Language Selection -->
                <div id="language-selection" class="mt-6" style="display: none;">
                    <label for="language-select" class="block text-sm font-medium text-gray-700 mb-2">
                        <?php _e('Select audio language:', 'transcribe-ai'); ?>
                    </label>
                    <select id="language-select" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php echo $code === 'en' ? 'selected' : ''; ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-gray-500 mt-1">
                        <?php _e('Choose the primary language spoken in your audio file for best results.', 'transcribe-ai'); ?>
                    </p>
                </div>
                
                <!-- Transcribe button -->
                <div id="transcribe-controls" class="mt-6" style="display: none;">
                    <button id="transcribeBtn" class="w-full py-3 px-6 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span class="material-symbols-outlined">transcribe</span>
                        <?php _e('Start Transcription', 'transcribe-ai'); ?>
                    </button>
                </div>
                
                <!-- Progress container -->
                <div id="progress-container" class="mt-6" style="display: none;"></div>
            </div>
            
            <!-- User Status Bar -->
            <div id="user-status-bar" class="bg-gray-50 px-8 py-4 border-t border-gray-200">
                <?php if ($user_data['is_logged_in']): ?>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-gray-600">account_circle</span>
                            <div>
                                <span class="font-medium text-gray-900">
                                    <?php echo esc_html($user_data['display_name'] ?? __('User', 'transcribe-ai')); ?>
                                </span>
                                <span class="text-sm text-gray-500 ml-2">
                                    <?php echo esc_html($user_data['plan_name']); ?> <?php _e('Plan', 'transcribe-ai'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="text-right">
                            <?php if ($user_data['role'] === 'premium'): ?>
                                <span class="text-sm font-medium text-green-600">
                                    <?php _e('Unlimited transcriptions', 'transcribe-ai'); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-sm text-gray-600">
                                    <span class="font-medium text-gray-900"><?php echo esc_html($user_data['minutes_remaining']); ?></span>
                                    <?php _e('minutes remaining this month', 'transcribe-ai'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Guest User Status -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-yellow-600 mt-0.5">info</span>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-900 mb-1">
                                    <?php _e('Guest Access', 'transcribe-ai'); ?>
                                </h4>
                                <p class="text-sm text-gray-700">
                                    <?php _e('You have', 'transcribe-ai'); ?>
                                    <strong><?php echo esc_html($user_data['minutes_remaining']); ?></strong>
                                    <?php _e('minutes remaining this month as a guest.', 'transcribe-ai'); ?>
                                </p>
                                <p class="text-sm text-gray-600 mt-2">
                                    <a href="<?php echo wp_login_url(get_permalink()); ?>" class="text-blue-600 hover:underline font-medium">
                                        <?php _e('Log in', 'transcribe-ai'); ?>
                                    </a>
                                    <?php _e('to save transcripts and get 120 minutes monthly, or upgrade to Premium for unlimited access.', 'transcribe-ai'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Features Section -->
        <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg p-6 shadow-sm">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-4">
                    <span class="material-symbols-outlined text-blue-600">translate</span>
                </div>
                <h3 class="font-semibold text-gray-900 mb-2">
                    <?php _e('17+ Languages', 'transcribe-ai'); ?>
                </h3>
                <p class="text-sm text-gray-600">
                    <?php _e('Transcribe audio in English, Spanish, French, German, Chinese, Japanese, and more', 'transcribe-ai'); ?>
                </p>
            </div>
            
            <div class="bg-white rounded-lg p-6 shadow-sm">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mb-4">
                    <span class="material-symbols-outlined text-green-600">group</span>
                </div>
                <h3 class="font-semibold text-gray-900 mb-2">
                    <?php _e('Speaker Detection', 'transcribe-ai'); ?>
                </h3>
                <p class="text-sm text-gray-600">
                    <?php _e('Automatically identify and label different speakers in your audio', 'transcribe-ai'); ?>
                </p>
            </div>
            
            <div class="bg-white rounded-lg p-6 shadow-sm">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mb-4">
                    <span class="material-symbols-outlined text-purple-600">no_accounts</span>
                </div>
                <h3 class="font-semibold text-gray-900 mb-2">
                    <?php _e('No Account Required', 'transcribe-ai'); ?>
                </h3>
                <p class="text-sm text-gray-600">
                    <?php _e('Get started immediately with 20 free minutes monthly - no signup needed', 'transcribe-ai'); ?>
                </p>
            </div>
        </div>
        
        <!-- Usage Tiers -->
        <div class="mt-12 bg-white rounded-lg p-6 shadow-sm">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <?php _e('Usage Plans', 'transcribe-ai'); ?>
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="border border-gray-200 rounded-lg p-4 <?php echo !$user_data['is_logged_in'] ? 'ring-2 ring-blue-500' : ''; ?>">
                    <h4 class="font-medium text-gray-900">
                        <?php _e('Guest', 'transcribe-ai'); ?>
                    </h4>
                    <p class="text-2xl font-bold text-gray-900 mt-2">
                        20 <span class="text-sm font-normal text-gray-500"><?php _e('min/month', 'transcribe-ai'); ?></span>
                    </p>
                    <ul class="mt-3 space-y-1 text-sm text-gray-600">
                        <li>✓ <?php _e('No signup required', 'transcribe-ai'); ?></li>
                        <li>✓ <?php _e('All languages', 'transcribe-ai'); ?></li>
                        <li>✗ <?php _e("Can't save transcripts", 'transcribe-ai'); ?></li>
                    </ul>
                </div>
                
                <div class="border border-gray-200 rounded-lg p-4 <?php echo $user_data['is_logged_in'] && $user_data['role'] === 'basic' ? 'ring-2 ring-blue-500' : ''; ?>">
                    <h4 class="font-medium text-gray-900">
                        <?php _e('Basic (Free)', 'transcribe-ai'); ?>
                    </h4>
                    <p class="text-2xl font-bold text-gray-900 mt-2">
                        120 <span class="text-sm font-normal text-gray-500"><?php _e('min/month', 'transcribe-ai'); ?></span>
                    </p>
                    <ul class="mt-3 space-y-1 text-sm text-gray-600">
                        <li>✓ <?php _e('Save & manage transcripts', 'transcribe-ai'); ?></li>
                        <li>✓ <?php _e('Edit transcripts', 'transcribe-ai'); ?></li>
                        <li>✓ <?php _e('Export (SRT, VTT, TXT)', 'transcribe-ai'); ?></li>
                    </ul>
                </div>
                
                <div class="border border-gray-200 rounded-lg p-4 <?php echo $user_data['is_logged_in'] && $user_data['role'] === 'premium' ? 'ring-2 ring-green-500' : ''; ?>">
                    <h4 class="font-medium text-gray-900">
                        <?php _e('Premium', 'transcribe-ai'); ?>
                    </h4>
                    <p class="text-2xl font-bold text-gray-900 mt-2">
                        <?php _e('Unlimited', 'transcribe-ai'); ?>
                    </p>
                    <ul class="mt-3 space-y-1 text-sm text-gray-600">
                        <li>✓ <?php _e('Everything in Basic', 'transcribe-ai'); ?></li>
                        <li>✓ <?php _e('Unlimited transcriptions', 'transcribe-ai'); ?></li>
                        <li>✓ <?php _e('Priority support', 'transcribe-ai'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Help Text -->
        <div class="mt-8 text-center text-sm text-gray-500">
            <p>
                <?php _e('Need help?', 'transcribe-ai'); ?>
                <a href="#" class="text-blue-600 hover:underline">
                    <?php _e('View documentation', 'transcribe-ai'); ?>
                </a>
                <?php _e('or', 'transcribe-ai'); ?>
                <a href="#" class="text-blue-600 hover:underline">
                    <?php _e('contact support', 'transcribe-ai'); ?>
                </a>
            </p>
        </div>
    </div>
</div>