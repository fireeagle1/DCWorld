import Foundation

// MARK: - Auth

struct LoginRequest: Codable {
    let email: String
    let password: String
}

struct LoginResponse: Codable {
    let token: String
    let user: User
}

struct User: Codable, Identifiable {
    let id: Int
    let name: String
}

// MARK: - Events

struct CalendarEvent: Codable, Identifiable {
    let id: Int
    let title: String
    let start: String
    let end: String?
    let location: String
    let allDay: Bool
    let source: String

    var startDate: Date? { DateFormatters.apiDateTime.date(from: start) }
    var endDate: Date? { end.flatMap { DateFormatters.apiDateTime.date(from: $0) } }
}

struct CreateEventRequest: Codable {
    let title: String
    let start: String
    let end: String?
    let location: String?
    let allDay: Bool
    let source: String
}

struct UpdateEventRequest: Codable {
    let id: Int
    let title: String
    let start: String
    let end: String?
    let location: String?
    let allDay: Bool
    let source: String
}

// MARK: - Daily Ops

struct DailyOpsDay: Codable, Identifiable {
    var id: String { date }

    let date: String
    let notes: String
    let ckOnCall: Bool
    let dcOnCall: Bool
    let ckLocation: LocationInfo
    let dcLocation: LocationInfo
    let ckWorkLocation: LocationInfo
    let dcWorkLocation: LocationInfo

    var dateValue: Date? { DateFormatters.apiDate.date(from: date) }
}

struct LocationInfo: Codable {
    let id: Int?
    let name: String
    let icon: String
}

struct LocationOption: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let icon: String
}

struct UpdateDailyOpsRequest: Codable {
    let date: String
    let ckLocation: Int?
    let dcLocation: Int?
    let ckWorkLocation: Int?
    let dcWorkLocation: Int?
    let ckOnCall: Bool
    let dcOnCall: Bool
    let notes: String
}

// MARK: - Contacts

struct Contact: Codable, Identifiable {
    let id: Int
    let knownAs: String
    let firstName: String
    let lastName: String
    let email: String
    let phone: String
    let dob: String?
    let streetAddress: String
    let city: String
    let postcode: String
    let photoURL: String?

    var fullName: String { "\(firstName) \(lastName)" }
    var dobDate: Date? { dob.flatMap { DateFormatters.apiDate.date(from: $0) } }
}

struct CreateContactRequest: Codable {
    let knownAs: String
    let firstName: String
    let lastName: String
    let email: String?
    let phone: String?
    let dob: String?
    let streetAddress: String?
    let city: String?
    let postcode: String?
}

struct UpdateContactRequest: Codable {
    let id: Int
    let knownAs: String
    let firstName: String
    let lastName: String
    let email: String?
    let phone: String?
    let dob: String?
    let streetAddress: String?
    let city: String?
    let postcode: String?
}

// MARK: - Bookings

struct Booking: Codable, Identifiable {
    let id: Int
    let start: String
    let end: String
    let occasion: String
    let status: String
    let notes: String
    let room: RoomInfo
    let guests: [GuestInfo]

    var startDate: Date? { DateFormatters.apiDateTime.date(from: start) }
    var endDate: Date? { DateFormatters.apiDateTime.date(from: end) }
}

struct RoomInfo: Codable {
    let id: Int?
    let name: String
}

struct GuestInfo: Codable, Identifiable {
    let id: Int
    let name: String
    let photoURL: String?
}

// MARK: - Generic Responses

struct DeleteResponse: Codable {
    let deleted: Bool
    let id: Int
}

// MARK: - API Error

struct APIError: Codable {
    let error: String
}
