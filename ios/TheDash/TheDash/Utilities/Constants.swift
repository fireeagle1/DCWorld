import Foundation

enum AppConstants {
    /// Base URL for the Tyche API. Change this to your production domain.
    /// For local development with XAMPP, use your Mac's local network IP.
    static let baseURL = "https://tyche.dcworld.uk/api"

    /// Keychain key for storing the JWT token
    static let tokenKey = "auth_token"

    /// Keychain key for stored user info
    static let userKey = "auth_user"
}
