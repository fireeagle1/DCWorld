import Foundation
import SwiftUI

/// Observable auth state manager. Used as an environment object throughout the app.
final class AuthManager: ObservableObject {
    @Published var isAuthenticated = false
    @Published var currentUser: User?
    @Published var isLoading = false
    @Published var errorMessage: String?

    init() {
        // Check for existing token on launch
        if let token = KeychainHelper.loadString(key: AppConstants.tokenKey), !token.isEmpty {
            isAuthenticated = true
            // Restore user info if saved
            if let userData = KeychainHelper.load(key: AppConstants.userKey),
               let user = try? JSONDecoder().decode(User.self, from: userData) {
                currentUser = user
            }
        }
    }

    @MainActor
    func login(email: String, password: String) async {
        isLoading = true
        errorMessage = nil

        do {
            let response = try await APIService.shared.login(email: email, password: password)

            // Store token securely
            KeychainHelper.save(response.token, for: AppConstants.tokenKey)

            // Store user info
            if let userData = try? JSONEncoder().encode(response.user) {
                KeychainHelper.save(userData, for: AppConstants.userKey)
            }

            currentUser = response.user
            isAuthenticated = true
        } catch let error as APIError {
            errorMessage = error.error
        } catch {
            errorMessage = "Connection failed. Check your internet."
        }

        isLoading = false
    }

    func logout() {
        KeychainHelper.delete(key: AppConstants.tokenKey)
        KeychainHelper.delete(key: AppConstants.userKey)
        currentUser = nil
        isAuthenticated = false
    }
}
