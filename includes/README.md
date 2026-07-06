# Includes Directory

This directory contains reusable PHP components and AJAX endpoints for the FleetGo system.

## AJAX Endpoints

### `ajax_receipt.php`
- **Purpose**: Fetch receipt data for completed rentals
- **Method**: GET
- **Parameters**: `rental_id`
- **Returns**: JSON with receipt details including charges breakdown

### `ajax_approve_rental.php`
- **Purpose**: Approve pending rentals
- **Method**: POST
- **Parameters**: `rental_id`
- **Returns**: JSON success/error message

### `ajax_reject_rental.php`
- **Purpose**: Reject pending rentals
- **Method**: POST
- **Parameters**: `rental_id`
- **Returns**: JSON success/error message

### `ajax_get_rental_details.php`
- **Purpose**: Get detailed rental information
- **Method**: GET
- **Parameters**: `rental_id`
- **Returns**: JSON with rental details

### `ajax_vehicle_history.php`
- **Purpose**: Get vehicle maintenance history
- **Method**: GET
- **Parameters**: `id` (vehicle_id)
- **Returns**: HTML table with maintenance records

### `book_vehicle.php`
- **Purpose**: Process vehicle booking requests
- **Method**: POST
- **Parameters**: `vehicle_id`, `start_date`, `end_date`, `rate_type`
- **Returns**: JSON success/error message with booking details

### `calculate_promo.php`
- **Purpose**: Calculate promotional rates for rentals
- **Method**: POST
- **Parameters**: `vehicle_id`, `start_date`, `end_date`, `rate_type`
- **Returns**: JSON with promotional rate calculations

### `fetch_user_rentals.php`
- **Purpose**: Fetch user rental history
- **Method**: GET
- **Parameters**: `user_id`
- **Returns**: HTML table with user's rental history

### `get_vehicle_info.php`
- **Purpose**: Get vehicle information for admin use
- **Method**: GET
- **Parameters**: `vehicle_id`
- **Returns**: JSON with vehicle details

### `preferences.php`
- **Purpose**: Handle user notification preferences
- **Method**: GET/POST
- **Parameters**: `email`, `sms`, `news` (for POST)
- **Returns**: JSON with preferences or success status

### `update_rental.php`
- **Purpose**: Update rental status and extend rentals
- **Method**: POST
- **Parameters**: `rental_id`, `status`, `end_date`
- **Returns**: Success message

## Other Components

### `db.php`
- Database connection and configuration

### `navbar.php`
- Admin navigation bar

### `user_navbar.php`
- User navigation bar

### `footer.php`
- Page footer

### `check_profile.php`
- Profile validation logic

### `notify.php`
- Notification system

### `promo_calculator.php`
- Promotion calculation logic

### `promo_policies.php`
- Promotion policies

### `styles.css`
- Additional CSS styles

## Benefits of This Structure

1. **Separation of Concerns**: AJAX endpoints are separate from main pages
2. **Clean JSON Responses**: No HTML interference with AJAX responses
3. **Reusability**: Components can be used across multiple pages
4. **Maintainability**: Easier to find and modify specific functionality
5. **Security**: Centralized authentication and error handling
6. **Performance**: Cleaner code execution without unnecessary HTML output
