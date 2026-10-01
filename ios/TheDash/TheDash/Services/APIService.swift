import Foundation

/// Central networking layer for communicating with the Tyche API.
final class APIService {
    static let shared = APIService()
    private init() {}

    private let session = URLSession.shared
    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        return d
    }()
    private let encoder: JSONEncoder = {
        let e = JSONEncoder()
        return e
    }()

    // MARK: - Generic Request

    func request<T: Decodable>(
        endpoint: String,
        method: String = "GET",
        body: (any Encodable)? = nil,
        queryItems: [URLQueryItem]? = nil,
        authenticated: Bool = true
    ) async throws -> T {
        guard var urlComponents = URLComponents(string: "\(AppConstants.baseURL)/\(endpoint)") else {
            throw APIError(error: "Invalid URL")
        }

        if let queryItems {
            urlComponents.queryItems = queryItems
        }

        guard let url = urlComponents.url else {
            throw APIError(error: "Invalid URL components")
        }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        if authenticated, let token = KeychainHelper.loadString(key: AppConstants.tokenKey) {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        if let body {
            request.httpBody = try encoder.encode(body)
        }

        let (data, response) = try await session.data(for: request)

        guard let httpResponse = response as? HTTPURLResponse else {
            throw APIError(error: "Invalid response")
        }

        if httpResponse.statusCode == 401 {
            // Token expired — clear auth
            KeychainHelper.delete(key: AppConstants.tokenKey)
            throw APIError(error: "Session expired. Please log in again.")
        }

        guard (200...299).contains(httpResponse.statusCode) else {
            if let apiError = try? decoder.decode(APIError.self, from: data) {
                throw apiError
            }
            throw APIError(error: "Request failed with status \(httpResponse.statusCode)")
        }

        return try decoder.decode(T.self, from: data)
    }

    // MARK: - Auth

    func login(email: String, password: String) async throws -> LoginResponse {
        let body = LoginRequest(email: email, password: password)
        return try await request(
            endpoint: "login.php",
            method: "POST",
            body: body,
            authenticated: false
        )
    }

    // MARK: - Events

    func fetchEvents(start: String, end: String) async throws -> [CalendarEvent] {
        try await request(
            endpoint: "events.php",
            queryItems: [
                URLQueryItem(name: "start", value: start),
                URLQueryItem(name: "end", value: end),
            ]
        )
    }

    func fetchEvent(id: Int) async throws -> CalendarEvent {
        try await request(
            endpoint: "events.php",
            queryItems: [URLQueryItem(name: "id", value: "\(id)")]
        )
    }

    func createEvent(_ event: CreateEventRequest) async throws -> CalendarEvent {
        try await request(endpoint: "events.php", method: "POST", body: event)
    }

    func updateEvent(_ event: UpdateEventRequest) async throws -> CalendarEvent {
        try await request(endpoint: "events.php", method: "PUT", body: event)
    }

    @discardableResult
    func deleteEvent(id: Int) async throws -> DeleteResponse {
        try await request(
            endpoint: "events.php",
            method: "DELETE",
            queryItems: [URLQueryItem(name: "id", value: "\(id)")]
        )
    }

    // MARK: - Daily Ops

    func fetchDailyOps(start: String, end: String) async throws -> [DailyOpsDay] {
        try await request(
            endpoint: "daily_ops.php",
            queryItems: [
                URLQueryItem(name: "start", value: start),
                URLQueryItem(name: "end", value: end),
            ]
        )
    }

    func updateDailyOps(_ ops: UpdateDailyOpsRequest) async throws -> DailyOpsDay {
        try await request(endpoint: "daily_ops.php", method: "PUT", body: ops)
    }

    func fetchLocations() async throws -> [LocationOption] {
        try await request(
            endpoint: "daily_ops.php",
            queryItems: [URLQueryItem(name: "locations", value: "1")]
        )
    }

    func fetchWorkLocations() async throws -> [LocationOption] {
        try await request(
            endpoint: "daily_ops.php",
            queryItems: [URLQueryItem(name: "worklocations", value: "1")]
        )
    }

    // MARK: - Contacts

    func fetchContacts(search: String? = nil) async throws -> [Contact] {
        var items: [URLQueryItem] = []
        if let search, !search.isEmpty {
            items.append(URLQueryItem(name: "search", value: search))
        }
        return try await request(endpoint: "contacts.php", queryItems: items.isEmpty ? nil : items)
    }

    func fetchContact(id: Int) async throws -> Contact {
        try await request(
            endpoint: "contacts.php",
            queryItems: [URLQueryItem(name: "id", value: "\(id)")]
        )
    }

    func createContact(_ contact: CreateContactRequest) async throws -> Contact {
        try await request(endpoint: "contacts.php", method: "POST", body: contact)
    }

    func updateContact(_ contact: UpdateContactRequest) async throws -> Contact {
        try await request(endpoint: "contacts.php", method: "PUT", body: contact)
    }

    @discardableResult
    func deleteContact(id: Int) async throws -> DeleteResponse {
        try await request(
            endpoint: "contacts.php",
            method: "DELETE",
            queryItems: [URLQueryItem(name: "id", value: "\(id)")]
        )
    }

    // MARK: - Bookings

    func fetchBookings(start: String, end: String) async throws -> [Booking] {
        try await request(
            endpoint: "bookings.php",
            queryItems: [
                URLQueryItem(name: "start", value: start),
                URLQueryItem(name: "end", value: end),
            ]
        )
    }
}

// Make APIError throwable
extension APIError: LocalizedError {
    var errorDescription: String? { error }
}
