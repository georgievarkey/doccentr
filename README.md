# doccentr
## Table of Contents
1. [Introduction](#1-introduction)
2. [System Architecture](#2-system-architecture)
3. [Use Case Diagram](#3-use-case-diagram)
4. [Microservices](#4-microservices)
5. [Event-Driven Communication](#5-event-driven-communication)
6. [Step Functions Workflow](#6-step-functions-workflow)
7. [Infrastructure as Code](#7-infrastructure-as-code)
8. [Security Considerations](#8-security-considerations)
9. [Advantages of the Architecture](#9-advantages-of-the-architecture)
10. [Scaling Options](#10-scaling-options)
11. [Authentication and Authorization](#11-authentication-and-authorization)
12. [Implementation Guide](#12-implementation-guide)
13. [Testing](#13-testing)
14. [Monitoring and Logging](#14-monitoring-and-logging)
16. [Data Flow](#16-data-flow)
17. [Database Schemas](#17-database-schemas)
18. [System Workflow](#18-system-workflow)
19. [Service Breakdown](#19-service-breakdown)
20. [Conclusion](#15-conclusion)

## 1. Introduction

The Invoice Management System is designed as an event-driven microservices architecture with AWS Step Functions for workflow orchestration. It handles the creation, management, and processing of invoices, providing functionalities for user authentication, invoice generation, client management, order tracking, and PDF generation.

## 2. System Architecture

The system leverages the following AWS services:

- Amazon ECS (Elastic Container Service) for running microservices
- Amazon RDS (Relational Database Service) for individual MySQL databases
- Amazon Cognito for user authentication
- Amazon API Gateway for routing and API management
- Amazon EventBridge for event-driven communication between services
- AWS Step Functions for orchestrating complex workflows
- Amazon S3 for storing generated PDFs
- AWS KMS (Key Management Service) for managing encryption keys
- AWS Systems Manager Parameter Store for storing configuration and secrets

Here's the updated high-level architecture diagram:

<img width="806" alt="image" src="https://github.com/user-attachments/assets/2c317e8f-e132-49f7-ba5e-d48437572b68">

## 3. Use Case Diagram

Here's a use case diagram for the Invoice Management System:

```mermaid
graph TD
    A((User)) --> B[Register]
    A --> C[Login]
    A --> D[Create Invoice]
    A --> E[View Invoices]
    A --> F[Update Invoice]
    A --> G[Delete Invoice]
    A --> H[Create Client]
    A --> I[View Clients]
    A --> J[Create Order]
    A --> K[View Orders]
    L((System)) --> M[Generate PDF]
    L --> N[Send Email Notification]
    L --> O[Update Invoice Status]
```
## 4.  MicroServices

Here's a detailed breakdown of each service, its purpose, and main methods:

### Auth Service

**Purpose**: Handle user authentication and authorization.

**Methods**:
- `register(email, password)`: Register a new user.
- `login(email, password)`: Authenticate a user and return a JWT token.
- `verifyToken(token)`: Verify the validity of a JWT token.
- `resetPassword(email)`: Initiate the password reset process.

### Invoice Service

**Purpose**: Manage invoice creation, retrieval, and updates.

**Methods**:
- `createInvoice(clientId, items, dueDate)`: Create a new invoice.
- `getInvoice(invoiceId)`: Retrieve a specific invoice.
- `listInvoices(filters)`: Retrieve a list of invoices based on filters.
- `updateInvoiceStatus(invoiceId, status)`: Update the status of an invoice.
- `deleteInvoice(invoiceId)`: Delete an invoice (soft delete).

### Client Service

**Purpose**: Manage client information.

**Methods**:
- `createClient(name, email, address, phone)`: Create a new client.
- `getClient(clientId)`: Retrieve a specific client's information.
- `listClients(filters)`: Retrieve a list of clients based on filters.
- `updateClient(clientId, details)`: Update a client's information.
- `deleteClient(clientId)`: Delete a client (soft delete).

### Order Service

**Purpose**: Manage order information.

**Methods**:
- `createOrder(clientId, items)`: Create a new order.
- `getOrder(orderId)`: Retrieve a specific order.
- `listOrders(filters)`: Retrieve a list of orders based on filters.
- `updateOrderStatus(orderId, status)`: Update the status of an order.
- `deleteOrder(orderId)`: Delete an order (soft delete).

### PDF Service

**Purpose**: Generate PDF invoices.

**Methods**:
- `generatePDF(invoiceId)`: Generate a PDF for a given invoice.
- `getPDFUrl(invoiceId)`: Get the S3 URL for a generated PDF.

### Notification Service

**Purpose**: Send notifications to users and clients.

**Methods**:
- `sendInvoiceNotification(invoiceId, recipientEmail)`: Send an email notification about an invoice.
- `sendReminder(invoiceId)`: Send a reminder for an unpaid invoice.
- `sendStatusUpdate(invoiceId, status)`: Notify about an invoice status change.
