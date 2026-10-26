# Website Updates Report Plugin

A WordPress plugin that generates comprehensive activity reports for client reporting. Perfect for agencies that need to show their clients what work has been done on their website within a specific date range.

## Features

### Report Data Includes:
- **Pages**: New pages created and existing pages updated
- **Posts**: New blog posts published and existing posts updated
- **Media Files**: New images, videos, and documents uploaded
- **New Users**: User accounts created
- **Active Plugins**: Current plugin status and versions

### Key Capabilities:
- **Date Range Selection**: Choose any custom date range for reporting
- **Selective Reporting**: Choose which types of data to include in each report
- **Visual Summary**: At-a-glance statistics with colorful summary cards
- **Detailed Tables**: Complete lists with dates, authors, and direct links
- **Print/PDF Export**: Print-friendly layout for client delivery
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## Installation

1. Upload the `website-updates` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Updates Report' in the WordPress admin menu

## Updates

This plugin updates from GitHub releases of the public repo [smcniel24/Website-Updates-Plugin](https://github.com/smcniel24/Website-Updates-Plugin), not from WordPress.org. No site setup is needed: updates appear on the Plugins and Dashboard > Updates screens like any other plugin. If an update check fails, the reason is shown under the plugin on the Plugins screen.

**Releasing an update:**
1. Bump the version in both the plugin header (`Version:`) and `WEBSITE_UPDATES_VERSION` in `website-updates.php`.
2. Add the changes to `CHANGELOG.md`, then commit and push.
3. On GitHub, publish a release with the version as its tag (e.g. `v1.0.9`). The release notes appear in the "View details" popup.

Sites check for updates about every 12 hours. **Check again** on Dashboard > Updates checks immediately. A `.zip` attached to the release is used if present; otherwise the source zip GitHub generates for the tag is used.

## Usage

1. **Select Date Range**: Choose start and end dates for your report period
2. **Choose Report Options**: Check which types of data you want to include
3. **Generate Report**: Click "Generate Report" to create the report
4. **Review Results**: See summary statistics and detailed tables
5. **Export**: Use the "Export as PDF" button or print directly from browser

## Report Sections

### Summary Cards
Quick overview showing:
- Number of new and updated pages
- Number of new and updated posts
- Total media files uploaded
- New user accounts created
- Active plugin count

### Detailed Tables
Each section includes:
- **Pages/Posts**: Title, author, dates, category (posts), and action links
- **Media**: Thumbnails, file types, sizes, upload dates
- **Users**: Username, email, role, registration date
- **Plugins**: Name, version, author information

## Default Date Range

The plugin defaults to the last 30 days, but you can select any custom range.

## Requirements

- WordPress 5.8 or higher
- PHP 7.2 or higher
- Administrator access to generate reports

## File Structure

```
website-updates/
├── website-updates.php       # Main plugin file
├── includes/
│   └── class-github-updater.php  # Updates from GitHub releases
├── assets/
│   ├── css/
│   │   └── admin.css        # Styling for admin interface
│   └── js/
│       └── admin.js         # JavaScript for date picker and AJAX
└── README.md                # This file
```

## Customization

### Modifying Report Output
Edit the `generate_report_html()` method in `website-updates.php` to customize the report layout.

### Adding New Data Types
To track additional data:
1. Create a new `get_[type]_data()` method
2. Add it to the AJAX handler `ajax_generate_report()`
3. Update the HTML generation in `generate_report_html()`
4. Add a checkbox option in the admin form

### Styling
Modify `assets/css/admin.css` to change colors, fonts, or layout.

## Tips for Agencies

1. **Regular Reports**: Run monthly reports for recurring clients
2. **Project Milestones**: Generate reports at project completion
3. **Client Meetings**: Print reports for in-person presentations
4. **Documentation**: Keep reports as records of work completed

## Support

For issues or feature requests, contact your development team.

## Changelog

### Version 1.0.0
- Initial release
- Date range selection
- Multiple data type tracking
- Print/PDF export capability
- Responsive design

## License

GPL-2.0+ License
