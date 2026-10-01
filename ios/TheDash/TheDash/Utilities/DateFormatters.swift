import Foundation

enum DateFormatters {
    /// Parses "2025-07-14 09:30:00" from the API
    static let apiDateTime: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        f.locale = Locale(identifier: "en_GB")
        f.timeZone = TimeZone(identifier: "Europe/London")
        return f
    }()

    /// Parses "2025-07-14" date-only from the API
    static let apiDate: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale = Locale(identifier: "en_GB")
        f.timeZone = TimeZone(identifier: "Europe/London")
        return f
    }()

    /// Display format: "Mon 14 Jul"
    static let shortDay: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "EEE d MMM"
        f.locale = Locale(identifier: "en_GB")
        return f
    }()

    /// Display format: "14 July 2025"
    static let longDate: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "d MMMM yyyy"
        f.locale = Locale(identifier: "en_GB")
        return f
    }()

    /// Display format: "09:30"
    static let time: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "HH:mm"
        f.locale = Locale(identifier: "en_GB")
        return f
    }()
}
