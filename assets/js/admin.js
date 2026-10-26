jQuery(document).ready(function($) {
    'use strict';
    
    const reportForm = $('#updates-report-form');
    const reportResults = $('#report-results');
    const reportContent = $('#report-content');
    const exportPdfBtn = $('#export-pdf');
    const emailReportBtn = $('#email-report');
    const reportOptionCounts = $('.report-option-count');
    const syncFilebirdBtn = $('#sync-filebird-folders');
    const syncFilebirdResult = $('#sync-filebird-result');
    
    // Handle form submission
    reportForm.on('submit', function(e) {
        e.preventDefault();
        
        // Validate dates
        const startDate = $('#start_date').val();
        const endDate = $('#end_date').val();
        
        if (new Date(startDate) > new Date(endDate)) {
            alert('Start date must be before end date.');
            return;
        }
        
        // Show loading state
        reportResults.show();
        reportContent.html('');
        $('.report-loading').show();
        exportPdfBtn.hide();
        emailReportBtn.hide();
        reportOptionCounts.text('');
        
        // Prepare form data
        const formData = {
            action: 'generate_updates_report',
            nonce: websiteUpdates.nonce,
            start_date: startDate,
            end_date: endDate,
            include_pages: $('input[name="include_pages"]').is(':checked'),
            include_projects: $('input[name="include_projects"]').is(':checked'),
            include_posts: $('input[name="include_posts"]').is(':checked'),
            include_media: $('input[name="include_media"]').is(':checked'),
            include_filebird: $('input[name="include_filebird"]').is(':checked'),
            include_users: $('input[name="include_users"]').is(':checked'),
            include_plugins: $('input[name="include_plugins"]').is(':checked')
        };
        
        // Debug: Log checkbox states
        console.log('Checkbox states:', {
            pages: formData.include_pages,
            projects: formData.include_projects,
            posts: formData.include_posts,
            media: formData.include_media,
            filebird: formData.include_filebird,
            users: formData.include_users,
            plugins: formData.include_plugins
        });
        
        // Make AJAX request
        $.ajax({
            url: websiteUpdates.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                $('.report-loading').hide();
                
                if (response.success) {
                    reportContent.html(response.data.html);
                    reportContent.data('reportData', response);
                    exportPdfBtn.show();
                    emailReportBtn.css('display', 'inline-flex');
                    updateReportOptionCounts(response.data.data.summary || {});
                    // Smooth scroll to results
                    $('html, body').animate({
                        scrollTop: reportResults.offset().top - 50
                    }, 500);
                } else {
                    reportContent.html('<div class="notice notice-error"><p>' + 
                        (response.data.message || 'An error occurred while generating the report.') + 
                        '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $('.report-loading').hide();
                reportContent.html('<div class="notice notice-error"><p>An error occurred: ' + 
                    error + '</p></div>');
            }
        });
    });
    
    // Handle PDF export - print only the report content
    exportPdfBtn.on('click', function() {
        const printWindow = window.open('', '_blank');
        const reportHtml = reportContent.html();
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Website Activity Report</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; padding: 20px; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
                    th { background-color: #f6f7f7; font-weight: 600; }
                    .report-header { margin-bottom: 30px; }
                    .report-header h2 { margin: 0 0 10px 0; }
                    .report-period { color: #666; margin: 0; }
                    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
                    .summary-item { background: #f6f7f7; padding: 15px; border-radius: 4px; }
                    .summary-icon { font-size: 24px; margin-bottom: 8px; }
                    .summary-content h4 { margin: 0 0 5px 0; font-size: 14px; color: #666; }
                    .summary-stat { margin: 0; font-size: 18px; }
                    .detail-section { margin: 30px 0; page-break-inside: avoid; }
                    .detail-section h3 { border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
                    .detail-section h4 { margin-top: 20px; color: #23282d; }
                    .button { display: none !important; }
                    @media print {
                        .button { display: none !important; }
                    }
                </style>
            </head>
            <body>
                ${reportHtml}
            </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.focus();
        
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 250);
    });
    
    // Handle email report
    emailReportBtn.on('click', function() {
        const email = prompt('Enter email address to send the report to:');
        
        if (!email) {
            return;
        }
        
        // Simple email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            alert('Please enter a valid email address.');
            return;
        }
        
        emailReportBtn.prop('disabled', true).text('Sending...');
        
        const reportData = reportContent.data('reportData');
        const dateRange = reportData && reportData.data && reportData.data.date_range
            ? reportData.data.date_range
            : {
                start: $('#start_date').val(),
                end: $('#end_date').val()
            };
        
        $.ajax({
            url: websiteUpdates.ajaxUrl,
            type: 'POST',
            data: {
                action: 'email_updates_report',
                nonce: websiteUpdates.nonce,
                email: email,
                report_html: reportContent.html(),
                date_range: dateRange
            },
            success: function(response) {
                emailReportBtn.prop('disabled', false).text('Email Report');
                
                if (response.success) {
                    alert('Report sent successfully to ' + email);
                } else {
                    alert('Error sending email: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function() {
                emailReportBtn.prop('disabled', false).text('Email Report');
                alert('An error occurred while sending the email.');
            }
        });
    });
    
    // Remove old print event listeners
    // Add print-friendly styles when printing
    window.addEventListener('beforeprint', function() {
        $('body').addClass('printing-report');
    });
    
    window.addEventListener('afterprint', function() {
        $('body').removeClass('printing-report');
    });

    // Sync existing FileBird folders into activity log
    syncFilebirdBtn.on('click', function() {
        const defaultSyncText = syncFilebirdBtn.text();
        const endDate = $('#end_date').val();

        if (!confirm('Sync existing FileBird folders into the report log? This will add any folders not yet tracked.')) {
            return;
        }

        syncFilebirdBtn.prop('disabled', true).text('Syncing...');
        syncFilebirdResult.removeClass('is-success is-error').text('');

        $.ajax({
            url: websiteUpdates.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sync_filebird_folders',
                nonce: websiteUpdates.nonce,
                activity_date: endDate
            },
            success: function(response) {
                syncFilebirdBtn.prop('disabled', false).text(defaultSyncText);

                if (response.success) {
                    syncFilebirdResult.addClass('is-success').text(response.data.message || 'Sync complete.');
                } else {
                    syncFilebirdResult.addClass('is-error').text(response.data.message || 'Sync failed.');
                }
            },
            error: function() {
                syncFilebirdBtn.prop('disabled', false).text(defaultSyncText);
                syncFilebirdResult.addClass('is-error').text('An error occurred while syncing.');
            }
        });
    });
    
    // Quick date range presets
    $('.date-preset').on('click', function(e) {
        e.preventDefault();
        const preset = $(this).data('preset');
        const today = new Date();
        let startDate;
        
        switch(preset) {
            case 'week':
                startDate = new Date(today.getTime() - (7 * 24 * 60 * 60 * 1000));
                break;
            case 'month':
                startDate = new Date(today.getFullYear(), today.getMonth() - 1, today.getDate());
                break;
            case 'quarter':
                startDate = new Date(today.getFullYear(), today.getMonth() - 3, today.getDate());
                break;
            case 'year':
                startDate = new Date(today.getFullYear() - 1, today.getMonth(), today.getDate());
                break;
        }
        
        if (startDate) {
            $('#start_date').val(formatDate(startDate));
            $('#end_date').val(formatDate(today));
        }
    });
    
    // Helper function to format date as YYYY-MM-DD
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function formatCount(value) {
        const number = Number(value) || 0;
        return number.toLocaleString();
    }

    function updateReportOptionCounts(summary) {
        const countMap = {
            pages: summary.pages ? ` (${formatCount(summary.pages.new)} new, ${formatCount(summary.pages.updated)} updated)` : '',
            projects: summary.projects ? ` (${formatCount(summary.projects.new)} new, ${formatCount(summary.projects.updated)} updated)` : '',
            posts: summary.posts ? ` (${formatCount(summary.posts.new)} new, ${formatCount(summary.posts.updated)} updated)` : '',
            media: summary.media !== undefined ? ` (${formatCount(summary.media)} uploaded)` : '',
            filebird: summary.filebird ? ` (${formatCount(summary.filebird.created)} new, ${formatCount(summary.filebird.updated)} updated, ${formatCount(summary.filebird.deleted)} deleted)` : '',
            users: summary.users !== undefined ? ` (${formatCount(summary.users)} new)` : '',
            plugins: summary.plugins !== undefined ? ` (${formatCount(summary.plugins)} updates)` : ''
        };

        reportOptionCounts.each(function() {
            const key = $(this).data('reportCount');
            if (key && Object.prototype.hasOwnProperty.call(countMap, key)) {
                $(this).text(countMap[key]);
            }
        });
    }
});
